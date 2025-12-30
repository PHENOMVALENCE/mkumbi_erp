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

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve') {
        try {
            $stmt = $conn->prepare("UPDATE expense_claims SET 
                status = 'approved', 
                approved_by = ?, 
                approved_at = NOW() 
                WHERE claim_id = ? AND company_id = ?");
            $stmt->execute([$user_id, $_POST['claim_id'], $company_id]);
            $success = "Expense claim approved successfully!";
        } catch (PDOException $e) {
            error_log("Error approving claim: " . $e->getMessage());
            $errors[] = "Error approving claim";
        }
    } elseif ($action === 'reject') {
        try {
            $stmt = $conn->prepare("UPDATE expense_claims SET 
                status = 'rejected', 
                approved_by = ?, 
                approved_at = NOW(),
                rejection_reason = ?
                WHERE claim_id = ? AND company_id = ?");
            $stmt->execute([
                $user_id, 
                $_POST['rejection_reason'] ?? 'No reason provided',
                $_POST['claim_id'], 
                $company_id
            ]);
            $success = "Expense claim rejected!";
        } catch (PDOException $e) {
            error_log("Error rejecting claim: " . $e->getMessage());
            $errors[] = "Error rejecting claim";
        }
    } elseif ($action === 'pay') {
        try {
            $stmt = $conn->prepare("UPDATE expense_claims SET 
                status = 'paid', 
                paid_by = ?, 
                paid_at = NOW(),
                payment_reference = ?
                WHERE claim_id = ? AND company_id = ? AND status = 'approved'");
            $stmt->execute([
                $user_id,
                $_POST['payment_reference'] ?? NULL,
                $_POST['claim_id'], 
                $company_id
            ]);
            $success = "Payment recorded successfully!";
        } catch (PDOException $e) {
            error_log("Error recording payment: " . $e->getMessage());
            $errors[] = "Error recording payment";
        }
    } elseif ($action === 'delete') {
        try {
            // Delete claim items first
            $stmt = $conn->prepare("DELETE FROM expense_claim_items WHERE claim_id = ?");
            $stmt->execute([$_POST['claim_id']]);
            
            // Delete claim
            $stmt = $conn->prepare("DELETE FROM expense_claims WHERE claim_id = ? AND company_id = ?");
            $stmt->execute([$_POST['claim_id'], $company_id]);
            $success = "Expense claim deleted successfully!";
        } catch (PDOException $e) {
            error_log("Error deleting claim: " . $e->getMessage());
            $errors[] = "Error deleting claim";
        }
    }
}

// Fetch filter parameters
$status_filter = $_GET['status'] ?? '';
$employee_filter = $_GET['employee'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_clauses = ["ec.company_id = ?"];
$params = [$company_id];

if ($status_filter) {
    $where_clauses[] = "ec.status = ?";
    $params[] = $status_filter;
}

if ($employee_filter) {
    $where_clauses[] = "ec.employee_id = ?";
    $params[] = $employee_filter;
}

if ($date_from) {
    $where_clauses[] = "ec.claim_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "ec.claim_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_clauses[] = "(ec.claim_number LIKE ? OR ec.description LIKE ? OR u.full_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch expense claims
try {
    $stmt = $conn->prepare("
        SELECT 
            ec.*,
            u_emp.full_name as employee_name,
            e.employee_number,
            d.department_name,
            u.full_name as creator_name,
            approver.full_name as approver_name,
            payer.full_name as payer_name,
            (SELECT COUNT(*) FROM expense_claim_items eci WHERE eci.claim_id = ec.claim_id) as item_count
        FROM expense_claims ec
        LEFT JOIN employees e ON ec.employee_id = e.employee_id
        LEFT JOIN users u_emp ON e.user_id = u_emp.user_id
        LEFT JOIN departments d ON ec.department_id = d.department_id
        LEFT JOIN users u ON ec.created_by = u.user_id
        LEFT JOIN users approver ON ec.approved_by = approver.user_id
        LEFT JOIN users payer ON ec.paid_by = payer.user_id
        WHERE $where_sql
        ORDER BY ec.claim_date DESC, ec.created_at DESC
    ");
    $stmt->execute($params);
    $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching claims: " . $e->getMessage());
    $claims = [];
}

// Fetch employees for filter
try {
    $stmt = $conn->prepare("
        SELECT e.employee_id, u.full_name, e.employee_number
        FROM employees e
        INNER JOIN users u ON e.user_id = u.user_id
        WHERE e.company_id = ? AND e.is_active = 1
        ORDER BY u.full_name
    ");
    $stmt->execute([$company_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching employees: " . $e->getMessage());
    $employees = [];
}

// Calculate statistics
$total_claims = count($claims);
$total_amount = array_sum(array_column($claims, 'total_amount'));
$pending_claims = count(array_filter($claims, fn($c) => $c['status'] === 'pending_approval'));
$approved_claims = count(array_filter($claims, fn($c) => $c['status'] === 'approved'));
$paid_claims = count(array_filter($claims, fn($c) => $c['status'] === 'paid'));
$rejected_claims = count(array_filter($claims, fn($c) => $c['status'] === 'rejected'));

// Status badge function
function getStatusBadge($status) {
    $badges = [
        'draft' => 'secondary',
        'submitted' => 'info',
        'pending_approval' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'paid' => 'primary',
        'cancelled' => 'dark'
    ];
    $labels = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'pending_approval' => 'Pending Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled'
    ];
    $color = $badges[$status] ?? 'secondary';
    $label = $labels[$status] ?? ucfirst($status);
    return "<span class='badge bg-$color'>$label</span>";
}

$page_title = 'Expense Claims';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.danger { border-left-color: #dc3545; }

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.table-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.table-card .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.25rem 1.5rem;
    border: none;
}

.table-responsive {
    padding: 0;
}

.table {
    margin: 0;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
    white-space: nowrap;
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.action-buttons {
    display: flex;
    gap: 0.25rem;
    flex-wrap: nowrap;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
}

.amount-cell {
    font-weight: 600;
    color: #28a745;
}

.date-cell {
    color: #6c757d;
    font-size: 0.9rem;
}

.claim-number {
    font-weight: 600;
    color: #007bff;
}

.employee-name {
    font-weight: 500;
}

.badge {
    font-size: 0.75rem;
    padding: 0.35rem 0.65rem;
    font-weight: 600;
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.item-row {
    background: #f8f9fa;
    padding: 0.75rem;
    border-radius: 6px;
    margin-bottom: 0.5rem;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
    
    .table {
        font-size: 0.875rem;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-receipt text-primary me-2"></i>Expense Claims
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage employee expense claims and reimbursements</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="create_claim.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> Submit New Claim
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Errors:</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($total_claims); ?></div>
                    <div class="stats-label">Total Claims</div>
                    <small class="text-muted">TSH <?php echo number_format($total_amount, 2); ?></small>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($pending_claims); ?></div>
                    <div class="stats-label">Pending Approval</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($approved_claims); ?></div>
                    <div class="stats-label">Approved</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format($rejected_claims); ?></div>
                    <div class="stats-label">Rejected</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Claim number, employee..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="pending_approval" <?php echo $status_filter === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Employee</label>
                    <select name="employee" class="form-select">
                        <option value="">All Employees</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['employee_id']; ?>" <?php echo $employee_filter == $emp['employee_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['full_name']); ?> (<?php echo htmlspecialchars($emp['employee_number']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="claims.php" class="btn btn-secondary">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                    <a href="create_claim.php" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i> New Claim
                    </a>
                </div>
            </form>
        </div>

        <!-- Claims Table -->
        <div class="table-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Expense Claims
                    <span class="badge bg-light text-dark ms-2"><?php echo number_format($total_claims); ?> claims</span>
                </h5>
            </div>
            <div class="table-responsive">
                <?php if (empty($claims)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h4>No Expense Claims Found</h4>
                    <p class="text-muted">No expense claims match your current filters</p>
                    <a href="create_claim.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus-circle me-1"></i> Submit Your First Claim
                    </a>
                </div>
                <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Claim #</th>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Items</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Submitted By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($claims as $claim): ?>
                        <tr>
                            <td>
                                <span class="claim-number"><?php echo htmlspecialchars($claim['claim_number']); ?></span>
                            </td>
                            <td>
                                <div class="date-cell">
                                    <?php echo date('d M Y', strtotime($claim['claim_date'])); ?>
                                </div>
                            </td>
                            <td>
                                <div class="employee-name"><?php echo htmlspecialchars($claim['employee_name'] ?? 'N/A'); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($claim['employee_number'] ?? ''); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($claim['department_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge bg-secondary"><?php echo $claim['item_count']; ?> items</span>
                            </td>
                            <td>
                                <div class="amount-cell">
                                    TSH <?php echo number_format($claim['total_amount'], 2); ?>
                                </div>
                            </td>
                            <td><?php echo getStatusBadge($claim['status']); ?></td>
                            <td>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($claim['creator_name']); ?><br>
                                    <?php echo date('d M Y', strtotime($claim['created_at'])); ?>
                                </small>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" 
                                            class="btn btn-sm btn-info" 
                                            onclick="viewClaim(<?php echo $claim['claim_id']; ?>)"
                                            title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($claim['status'] === 'pending_approval'): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-success" 
                                            onclick="approveClaim(<?php echo $claim['claim_id']; ?>, '<?php echo htmlspecialchars($claim['claim_number']); ?>')"
                                            title="Approve">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" 
                                            class="btn btn-sm btn-danger" 
                                            onclick="rejectClaim(<?php echo $claim['claim_id']; ?>, '<?php echo htmlspecialchars($claim['claim_number']); ?>')"
                                            title="Reject">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($claim['status'] === 'approved'): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-primary" 
                                            onclick="payClaim(<?php echo $claim['claim_id']; ?>, '<?php echo htmlspecialchars($claim['claim_number']); ?>')"
                                            title="Record Payment">
                                        <i class="fas fa-dollar-sign"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($claim['status'], ['draft', 'rejected'])): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-danger" 
                                            onclick="deleteClaim(<?php echo $claim['claim_id']; ?>, '<?php echo htmlspecialchars($claim['claim_number']); ?>')"
                                            title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($claim['receipt_path']): ?>
                                    <a href="../../<?php echo htmlspecialchars($claim['receipt_path']); ?>" 
                                       class="btn btn-sm btn-secondary" 
                                       target="_blank"
                                       title="View Receipt">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5" class="text-end">Total:</th>
                            <th class="amount-cell">TSH <?php echo number_format($total_amount, 2); ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<!-- View Claim Modal -->
<div class="modal fade" id="viewClaimModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-receipt me-2"></i>Claim Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="claimDetailsContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle me-2"></i>Reject Claim
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="claim_id" id="reject_claim_id">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Are you sure you want to reject claim <strong id="reject_claim_number"></strong>?
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Provide a reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times-circle me-1"></i> Reject Claim
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-dollar-sign me-2"></i>Record Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="claim_id" id="pay_claim_id">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Record payment for claim <strong id="pay_claim_number"></strong>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Reference</label>
                        <input type="text" name="payment_reference" class="form-control" placeholder="e.g., CHQ123456, TRX789012">
                        <small class="text-muted">Optional: Cheque number, transaction ID, etc.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-1"></i> Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function viewClaim(claimId) {
    const modal = new bootstrap.Modal(document.getElementById('viewClaimModal'));
    modal.show();
    
    // Fetch claim details via AJAX
    fetch(`get_claim_details.php?claim_id=${claimId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('claimDetailsContent').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('claimDetailsContent').innerHTML = 
                '<div class="alert alert-danger">Error loading claim details</div>';
        });
}

function approveClaim(claimId, claimNumber) {
    if (confirm(`Are you sure you want to approve claim ${claimNumber}?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="claim_id" value="${claimId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function rejectClaim(claimId, claimNumber) {
    document.getElementById('reject_claim_id').value = claimId;
    document.getElementById('reject_claim_number').textContent = claimNumber;
    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
}

function payClaim(claimId, claimNumber) {
    document.getElementById('pay_claim_id').value = claimId;
    document.getElementById('pay_claim_number').textContent = claimNumber;
    const modal = new bootstrap.Modal(document.getElementById('payModal'));
    modal.show();
}

function deleteClaim(claimId, claimNumber) {
    if (confirm(`Are you sure you want to delete claim ${claimNumber}? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="claim_id" value="${claimId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php 
require_once '../../includes/footer.php';
?>