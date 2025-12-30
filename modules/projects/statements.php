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

// Get project ID from URL
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

if (!$project_id) {
    $_SESSION['error'] = "Project ID is required.";
    header('Location: creditors.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create') {
            // Create new statement
            $payment_date = $_POST['payment_date'];
            $amount_paid = (float)$_POST['amount_paid'];
            $payment_method = $_POST['payment_method'];
            $reference_number = $_POST['reference_number'] ?? '';
            $description = $_POST['description'] ?? '';
            $status = $_POST['status'] ?? 'paid';
            
            // Generate statement number
            $stmt = $conn->prepare("SELECT COUNT(*) FROM project_statements WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $count = $stmt->fetchColumn() + 1;
            $statement_number = 'STMT-' . date('Y') . '-' . str_pad($count, 7, '0', STR_PAD_LEFT);
            
            $query = "
                INSERT INTO project_statements (
                    company_id, project_id, statement_number, payment_date,
                    amount_paid, payment_method, reference_number, description,
                    status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->execute([
                $company_id, $project_id, $statement_number, $payment_date,
                $amount_paid, $payment_method, $reference_number, $description,
                $status, $user_id
            ]);
            
            $_SESSION['success'] = "Payment statement created successfully.";
            
        } elseif ($action === 'update') {
            // Update existing statement
            $statement_id = (int)$_POST['statement_id'];
            $payment_date = $_POST['payment_date'];
            $amount_paid = (float)$_POST['amount_paid'];
            $payment_method = $_POST['payment_method'];
            $reference_number = $_POST['reference_number'] ?? '';
            $description = $_POST['description'] ?? '';
            $status = $_POST['status'];
            
            $query = "
                UPDATE project_statements SET
                    payment_date = ?,
                    amount_paid = ?,
                    payment_method = ?,
                    reference_number = ?,
                    description = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE statement_id = ? AND company_id = ?
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->execute([
                $payment_date, $amount_paid, $payment_method, $reference_number,
                $description, $status, $statement_id, $company_id
            ]);
            
            $_SESSION['success'] = "Payment statement updated successfully.";
            
        } elseif ($action === 'delete') {
            // Delete statement
            $statement_id = (int)$_POST['statement_id'];
            
            $stmt = $conn->prepare("DELETE FROM project_statements WHERE statement_id = ? AND company_id = ?");
            $stmt->execute([$statement_id, $company_id]);
            
            $_SESSION['success'] = "Payment statement deleted successfully.";
        }
        
        header('Location: statements.php?project_id=' . $project_id);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error processing statement: " . $e->getMessage());
        $_SESSION['error'] = "Error processing payment statement: " . $e->getMessage();
    }
}

// ==================== FETCH PROJECT AND SELLER DETAILS ====================
try {
    $query = "
        SELECT 
            p.*,
            ps.seller_name,
            ps.seller_phone,
            ps.seller_nida,
            ps.seller_tin,
            ps.purchase_date,
            ps.purchase_amount as land_purchase_amount,
            COALESCE(SUM(CASE WHEN pst.status = 'paid' THEN pst.amount_paid ELSE 0 END), 0) as total_paid
        FROM projects p
        LEFT JOIN project_sellers ps ON p.project_id = ps.project_id AND p.company_id = ps.company_id
        LEFT JOIN project_statements pst ON p.project_id = pst.project_id AND p.company_id = pst.company_id
        WHERE p.project_id = ? AND p.company_id = ?
        GROUP BY p.project_id, ps.seller_name, ps.seller_phone, ps.seller_nida, ps.seller_tin, ps.purchase_date, ps.purchase_amount
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$project_id, $company_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        $_SESSION['error'] = "Project not found.";
        header('Location: creditors.php');
        exit;
    }
    
    $land_amount = floatval($project['land_purchase_amount'] ?? 0);
    $total_paid = floatval($project['total_paid'] ?? 0);
    $balance_due = $land_amount - $total_paid;
    $payment_percent = $land_amount > 0 ? ($total_paid / $land_amount) * 100 : 0;
    
} catch (PDOException $e) {
    error_log("Error fetching project: " . $e->getMessage());
    $_SESSION['error'] = "Error loading project data.";
    header('Location: creditors.php');
    exit;
}

// ==================== STATISTICS ====================
$stats = [
    'total_statements' => 0,
    'paid_count' => 0,
    'pending_count' => 0,
    'cancelled_count' => 0
];

// ==================== FETCH ALL STATEMENTS ====================
try {
    $query = "
        SELECT 
            ps.*,
            u.full_name as created_by_name
        FROM project_statements ps
        LEFT JOIN users u ON ps.created_by = u.user_id
        WHERE ps.project_id = ? AND ps.company_id = ?
        ORDER BY ps.payment_date DESC, ps.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$project_id, $company_id]);
    $statements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $stats['total_statements'] = count($statements);
    foreach ($statements as $s) {
        if ($s['status'] === 'paid') $stats['paid_count']++;
        elseif ($s['status'] === 'pending') $stats['pending_count']++;
        elseif ($s['status'] === 'cancelled') $stats['cancelled_count']++;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching statements: " . $e->getMessage());
    $statements = [];
}

$page_title = 'Payment Statements - ' . $project['project_name'];
require_once '../../includes/header.php';
?>

<style>
/* Stats Cards */
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid;
    height: 100%;
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.purple { border-left-color: #6f42c1; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.15rem;
    line-height: 1;
}

.stats-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #6c757d;
    font-weight: 600;
}

/* Project Info Card */
.project-info-card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    margin-bottom: 1rem;
}

.project-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.25rem;
    border-radius: 6px 6px 0 0;
}

.project-details {
    padding: 1.25rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 600;
}

.detail-value {
    font-size: 0.9rem;
    color: #2c3e50;
    font-weight: 500;
}

/* Progress Bar */
.payment-progress {
    height: 30px;
    background: #e9ecef;
    border-radius: 6px;
    overflow: hidden;
    margin: 1rem 0;
}

.payment-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #28a745 0%, #34d399 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 0.85rem;
    transition: width 0.5s ease;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.paid {
    background: #d4edda;
    color: #155724;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.status-badge.cancelled {
    background: #f8d7da;
    color: #721c24;
}

/* Table Styling */
.table-professional {
    font-size: 0.85rem;
}

.table-professional thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #495057;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.3px;
    padding: 0.65rem 0.5rem;
    white-space: nowrap;
}

.table-professional tbody td {
    padding: 0.65rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.table-professional tbody tr:hover {
    background-color: #f8f9fa;
}

.statement-number {
    font-weight: 700;
    color: #2c3e50;
    font-size: 0.85rem;
}

.payment-method {
    font-size: 0.8rem;
    color: #6c757d;
}

.amount-value {
    font-weight: 600;
    color: #2c3e50;
    white-space: nowrap;
}

.description-cell {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #6c757d;
    font-size: 0.8rem;
}

/* Action Buttons */
.action-btn {
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
    border-radius: 3px;
    margin-right: 0.2rem;
    white-space: nowrap;
}

/* Cards */
.main-card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
}

.empty-state i {
    font-size: 3rem;
    color: #dee2e6;
    margin-bottom: 1rem;
}

.empty-state p {
    color: #6c757d;
    font-size: 1rem;
}

/* Modal Styling */
.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
}

/* Responsive */
@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
    
    .stats-label {
        font-size: 0.7rem;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <a href="creditors.php" class="text-decoration-none text-muted me-2">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    Payment Statements
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <button type="button" class="btn btn-primary btn-sm" onclick="resetForm()" data-bs-toggle="modal" data-bs-target="#statementModal">
                    <i class="fas fa-plus me-1"></i>New Payment
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Project Info Card -->
    <div class="project-info-card">
        <div class="project-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h4 class="mb-1"><?= htmlspecialchars($project['project_name']) ?></h4>
                    <p class="mb-0 opacity-75">
                        <small><?= htmlspecialchars($project['project_code']) ?></small>
                    </p>
                </div>
                <div class="col-md-4 text-md-end mt-2 mt-md-0">
                    <?php if (!empty($project['seller_name'])): ?>
                        <div><i class="fas fa-user me-2"></i><?= htmlspecialchars($project['seller_name']) ?></div>
                        <?php if (!empty($project['seller_phone'])): ?>
                            <small><i class="fas fa-phone me-2"></i><?= htmlspecialchars($project['seller_phone']) ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="project-details">
            <div class="row">
                <div class="col-md-3">
                    <div class="detail-row">
                        <span class="detail-label">Land Purchase Amount:</span>
                        <span class="detail-value">TZS <?= number_format($land_amount, 0) ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="detail-row">
                        <span class="detail-label text-success">Total Paid:</span>
                        <span class="detail-value text-success">TZS <?= number_format($total_paid, 0) ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="detail-row">
                        <span class="detail-label <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?>">Balance Due:</span>
                        <span class="detail-value <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?>">TZS <?= number_format($balance_due, 0) ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="detail-row">
                        <span class="detail-label">Payment Status:</span>
                        <span class="detail-value">
                            <span class="status-badge <?= $balance_due <= 0 ? 'paid' : 'pending' ?>">
                                <?= $balance_due <= 0 ? 'Fully Paid' : 'Pending' ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="payment-progress">
                <div class="payment-progress-bar" style="width: <?= min($payment_percent, 100) ?>%">
                    <?= number_format($payment_percent, 1) ?>% Paid
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-2 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($stats['total_statements']) ?></div>
                <div class="stats-label">Total Statements</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($stats['paid_count']) ?></div>
                <div class="stats-label">Paid</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($stats['pending_count']) ?></div>
                <div class="stats-label">Pending</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($stats['cancelled_count']) ?></div>
                <div class="stats-label">Cancelled</div>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card main-card">
        <div class="card-body">
            <?php if (empty($statements)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-invoice"></i>
                    <p>No payment statements found</p>
                    <button type="button" class="btn btn-primary" onclick="resetForm()" data-bs-toggle="modal" data-bs-target="#statementModal">
                        <i class="fas fa-plus me-2"></i>Create First Payment Statement
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="statementsTable">
                        <thead>
                            <tr>
                                <th>Statement #</th>
                                <th>Payment Date</th>
                                <th class="text-end">Amount Paid</th>
                                <th>Payment Method</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($statements as $s): ?>
                                <tr>
                                    <td class="statement-number"><?= htmlspecialchars($s['statement_number']) ?></td>
                                    <td><?= date('d M Y', strtotime($s['payment_date'])) ?></td>
                                    <td class="text-end amount-value">TZS <?= number_format($s['amount_paid'], 0) ?></td>
                                    <td>
                                        <span class="payment-method"><?= ucwords(str_replace('_', ' ', $s['payment_method'])) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['reference_number'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($s['reference_number']) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['description'])): ?>
                                            <span class="description-cell" title="<?= htmlspecialchars($s['description']) ?>">
                                                <?= htmlspecialchars($s['description']) ?>
                                            </span>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $s['status'] ?>">
                                            <?= ucfirst($s['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= !empty($s['created_by_name']) ? htmlspecialchars($s['created_by_name']) : 'System' ?>
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary action-btn" 
                                                onclick='editStatement(<?= json_encode($s) ?>)' 
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger action-btn" 
                                                onclick="deleteStatement(<?= $s['statement_id'] ?>)" 
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="2" class="text-end"><strong>Total Paid:</strong></td>
                                <td class="text-end"><strong class="text-success">TZS <?= number_format($total_paid, 0) ?></strong></td>
                                <td colspan="6"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- DataTables -->
                <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
                <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

                <script>
                $(document).ready(function() {
                    $('#statementsTable').DataTable({
                        pageLength: 25,
                        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                        responsive: true,
                        order: [[1, 'desc']],
                        columnDefs: [
                            { 
                                targets: 2,
                                className: 'text-end'
                            },
                            { 
                                targets: 8,
                                orderable: false,
                                className: 'text-center'
                            }
                        ],
                        language: {
                            search: "Search:",
                            lengthMenu: "Show _MENU_ entries",
                            info: "Showing _START_ to _END_ of _TOTAL_ statements",
                            infoEmpty: "Showing 0 to 0 of 0 statements",
                            infoFiltered: "(filtered from _MAX_ total statements)",
                            paginate: {
                                first: "First",
                                last: "Last",
                                next: "Next",
                                previous: "Previous"
                            }
                        }
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Statement Modal -->
<div class="modal fade" id="statementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice-dollar me-2"></i>
                    <span id="modalTitleText">New Payment Statement</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="statementForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="statement_id" id="statementId">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="paymentDate" class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="paymentDate" name="payment_date" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="amountPaid" class="form-label">Amount Paid <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">TZS</span>
                                <input type="number" class="form-control" id="amountPaid" name="amount_paid" 
                                       step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="paymentMethod" class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select" id="paymentMethod" name="payment_method" required>
                                <option value="">Select method...</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="referenceNumber" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="referenceNumber" name="reference_number" 
                                   placeholder="Transaction/Cheque reference">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="paid">Paid</option>
                            <option value="pending">Pending</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description/Notes</label>
                        <textarea class="form-control" id="description" name="description" rows="3" 
                                  placeholder="Additional notes or payment details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Statement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this payment statement?</p>
                <p class="text-danger"><strong>This action cannot be undone.</strong></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="statement_id" id="deleteStatementId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Set today's date as default
document.getElementById('paymentDate').valueAsDate = new Date();

function resetForm() {
    document.getElementById('statementForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('statementId').value = '';
    document.getElementById('modalTitleText').textContent = 'New Payment Statement';
    document.getElementById('paymentDate').valueAsDate = new Date();
}

function editStatement(statement) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('statementId').value = statement.statement_id;
    document.getElementById('paymentDate').value = statement.payment_date;
    document.getElementById('amountPaid').value = statement.amount_paid;
    document.getElementById('paymentMethod').value = statement.payment_method;
    document.getElementById('referenceNumber').value = statement.reference_number || '';
    document.getElementById('status').value = statement.status;
    document.getElementById('description').value = statement.description || '';
    document.getElementById('modalTitleText').textContent = 'Edit Payment Statement';
    
    new bootstrap.Modal(document.getElementById('statementModal')).show();
}

function deleteStatement(statementId) {
    document.getElementById('deleteStatementId').value = statementId;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Auto-open modal if action=new in URL
<?php if (isset($_GET['action']) && $_GET['action'] === 'new'): ?>
window.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('statementModal')).show();
});
<?php endif; ?>
</script>

<?php require_once '../../includes/footer.php'; ?>