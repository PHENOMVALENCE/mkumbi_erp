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

// Handle write-off submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_writeoff') {
    try {
        $conn->beginTransaction();
        
        $customer_id = $_POST['customer_id'];
        $reservation_id = $_POST['reservation_id'];
        $writeoff_amount = $_POST['writeoff_amount'];
        $writeoff_reason = $_POST['writeoff_reason'];
        $notes = $_POST['notes'] ?? null;
        
        // Insert write-off record
        $writeoff_sql = "INSERT INTO customer_writeoffs (
            company_id, customer_id, reservation_id, writeoff_amount, 
            writeoff_reason, notes, writeoff_date, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'pending', ?)";
        
        $writeoff_stmt = $conn->prepare($writeoff_sql);
        $writeoff_stmt->execute([
            $company_id, $customer_id, $reservation_id, $writeoff_amount,
            $writeoff_reason, $notes, $_SESSION['user_id']
        ]);
        
        $conn->commit();
        $_SESSION['success_message'] = "Write-off request submitted successfully";
        header("Location: writeoffs.php");
        exit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Write-off error: " . $e->getMessage());
        $_SESSION['error_message'] = "Failed to create write-off: " . $e->getMessage();
    }
}

// Handle write-off approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_writeoff') {
    try {
        $conn->beginTransaction();
        
        $writeoff_id = $_POST['writeoff_id'];
        
        // Update write-off status
        $update_sql = "UPDATE customer_writeoffs 
                      SET status = 'approved', 
                          approved_by = ?, 
                          approved_date = CURDATE()
                      WHERE writeoff_id = ? AND company_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->execute([$_SESSION['user_id'], $writeoff_id, $company_id]);
        
        $conn->commit();
        $_SESSION['success_message'] = "Write-off approved successfully";
        header("Location: writeoffs.php");
        exit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Approval error: " . $e->getMessage());
        $_SESSION['error_message'] = "Failed to approve write-off";
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Fetch write-offs
$writeoffs_sql = "SELECT 
    w.*,
    c.full_name as customer_name,
    COALESCE(c.phone, c.phone1) as phone,
    r.reservation_number,
    CONCAT('Plot ', pl.plot_number) as plot_info,
    pr.project_name,
    creator.full_name as created_by_name,
    approver.full_name as approved_by_name
FROM customer_writeoffs w
INNER JOIN customers c ON w.customer_id = c.customer_id
LEFT JOIN reservations r ON w.reservation_id = r.reservation_id
LEFT JOIN plots pl ON r.plot_id = pl.plot_id
LEFT JOIN projects pr ON pl.project_id = pr.project_id
LEFT JOIN users creator ON w.created_by = creator.user_id
LEFT JOIN users approver ON w.approved_by = approver.user_id
WHERE w.company_id = ?";

$params = [$company_id];

if ($status_filter !== 'all') {
    $writeoffs_sql .= " AND w.status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $writeoffs_sql .= " AND (c.full_name LIKE ? OR r.reservation_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$writeoffs_sql .= " ORDER BY w.created_at DESC";

try {
    $stmt = $conn->prepare($writeoffs_sql);
    $stmt->execute($params);
    $writeoffs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching write-offs: " . $e->getMessage());
    $writeoffs = [];
}

// Calculate statistics
$total_writeoffs = count($writeoffs);
$pending_count = count(array_filter($writeoffs, fn($w) => $w['status'] === 'pending'));
$approved_count = count(array_filter($writeoffs, fn($w) => $w['status'] === 'approved'));
$rejected_count = count(array_filter($writeoffs, fn($w) => $w['status'] === 'rejected'));
$total_amount = array_sum(array_column($writeoffs, 'writeoff_amount'));
$approved_amount = array_sum(array_column(
    array_filter($writeoffs, fn($w) => $w['status'] === 'approved'),
    'writeoff_amount'
));

// Fetch customers with outstanding balances for new write-off
try {
    $customers_sql = "SELECT 
        c.customer_id,
        c.full_name as customer_name,
        COALESCE(c.phone, c.phone1) as phone,
        r.reservation_id,
        r.reservation_number,
        CONCAT('Plot ', pl.plot_number, ' - ', pr.project_name) as plot_info,
        r.total_amount,
        COALESCE(SUM(p.amount), 0) as total_paid,
        r.total_amount - COALESCE(SUM(p.amount), 0) as balance
    FROM customers c
    INNER JOIN reservations r ON c.customer_id = r.customer_id
    INNER JOIN plots pl ON r.plot_id = pl.plot_id
    INNER JOIN projects pr ON pl.project_id = pr.project_id
    LEFT JOIN payments p ON r.reservation_id = p.reservation_id AND p.status = 'approved'
    WHERE c.company_id = ? AND r.is_active = 1
    GROUP BY c.customer_id, r.reservation_id
    HAVING balance > 0
    ORDER BY c.full_name, r.reservation_number";
    
    $customers_stmt = $conn->prepare($customers_sql);
    $customers_stmt->execute([$company_id]);
    $customers_with_balance = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers_with_balance = [];
}

$page_title = 'Write-offs Management';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    height: 100%;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.danger { border-left-color: #dc3545; }

.stats-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    font-weight: 600;
}

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
    padding: 0.75rem 0.5rem;
}

.table-professional tbody td {
    padding: 0.75rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-badge.pending { background: #fff3cd; color: #856404; }
.status-badge.approved { background: #d4edda; color: #155724; }
.status-badge.rejected { background: #f8d7da; color: #721c24; }

.filter-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice-dollar text-danger me-2"></i>Write-offs Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage bad debts and uncollectible accounts</p>
            </div>
            <div class="col-sm-6 text-end">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createWriteoffModal">
                    <i class="fas fa-plus-circle me-1"></i>Create Write-off
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Display Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($total_writeoffs) ?></div>
                <div class="stats-label">Total Write-offs</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($pending_count) ?></div>
                <div class="stats-label">Pending Approval</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($approved_count) ?></div>
                <div class="stats-label">Approved</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card danger">
                <div class="stats-number">TZS <?= number_format($approved_amount / 1000000, 1) ?>M</div>
                <div class="stats-label">Written Off Amount</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>

            <div class="col-md-5">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="search" class="form-control" 
                       placeholder="Customer name or reservation number..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                    <a href="writeoffs.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Write-offs Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($writeoffs)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No write-offs found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="writeoffsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Reservation</th>
                                <th>Plot/Project</th>
                                <th class="text-end">Amount</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($writeoffs as $writeoff): ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($writeoff['writeoff_date'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($writeoff['customer_name']) ?></strong>
                                        <?php if ($writeoff['phone']): ?>
                                            <div class="small text-muted">
                                                <?= htmlspecialchars($writeoff['phone']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="payment-number" style="font-family: monospace; background: #f8f9fa; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                            <?= htmlspecialchars($writeoff['reservation_number']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($writeoff['plot_info']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($writeoff['project_name']) ?></small>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-danger">
                                            TZS <?= number_format($writeoff['writeoff_amount'], 0) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= ucfirst(str_replace('_', ' ', $writeoff['writeoff_reason'])) ?></span>
                                        <?php if ($writeoff['notes']): ?>
                                            <div class="small text-muted mt-1"><?= htmlspecialchars($writeoff['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $writeoff['status'] ?>">
                                            <?= ucfirst($writeoff['status']) ?>
                                        </span>
                                        <?php if ($writeoff['status'] === 'approved' && $writeoff['approved_by_name']): ?>
                                            <div class="small text-muted mt-1">
                                                By: <?= htmlspecialchars($writeoff['approved_by_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($writeoff['created_by_name']) ?>
                                        <div class="small text-muted">
                                            <?= date('M d, Y', strtotime($writeoff['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($writeoff['status'] === 'pending'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="approve_writeoff">
                                                <input type="hidden" name="writeoff_id" value="<?= $writeoff['writeoff_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success" 
                                                        onclick="return confirm('Approve this write-off?')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- DataTables -->
                <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
                <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

                <script>
                $(document).ready(function() {
                    $('#writeoffsTable').DataTable({
                        pageLength: 25,
                        order: [[0, 'desc']],
                        columnDefs: [
                            { targets: [4], className: 'text-end' },
                            { targets: 8, orderable: false }
                        ]
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Write-off Modal -->
<div class="modal fade" id="createWriteoffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Write-off</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_writeoff">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Customer & Reservation <span class="text-danger">*</span></label>
                        <select name="reservation_id" id="reservation_select" class="form-select" required onchange="updateWriteoffDetails()">
                            <option value="">Select Customer & Reservation</option>
                            <?php foreach ($customers_with_balance as $cust): ?>
                                <option value="<?= $cust['reservation_id'] ?>" 
                                        data-customer-id="<?= $cust['customer_id'] ?>"
                                        data-balance="<?= $cust['balance'] ?>"
                                        data-customer-name="<?= htmlspecialchars($cust['customer_name']) ?>">
                                    <?= htmlspecialchars($cust['customer_name']) ?> - <?= htmlspecialchars($cust['reservation_number']) ?> 
                                    (<?= $cust['plot_info'] ?>) - Balance: TZS <?= number_format($cust['balance'], 0) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="customer_id" id="customer_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Write-off Amount <span class="text-danger">*</span></label>
                        <input type="number" name="writeoff_amount" id="writeoff_amount" 
                               class="form-control" step="0.01" required>
                        <small class="text-muted">Available balance: <span id="available_balance">0</span></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                        <select name="writeoff_reason" class="form-select" required>
                            <option value="">Select Reason</option>
                            <option value="bankruptcy">Bankruptcy</option>
                            <option value="deceased">Customer Deceased</option>
                            <option value="uncollectible">Uncollectible</option>
                            <option value="dispute">Unresolved Dispute</option>
                            <option value="fraud">Fraud</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" 
                                  placeholder="Additional details about this write-off..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-file-invoice-dollar me-1"></i>Submit Write-off
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateWriteoffDetails() {
    const select = document.getElementById('reservation_select');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const customerId = selectedOption.getAttribute('data-customer-id');
        const balance = parseFloat(selectedOption.getAttribute('data-balance'));
        
        document.getElementById('customer_id').value = customerId;
        document.getElementById('available_balance').textContent = 'TZS ' + balance.toLocaleString();
        document.getElementById('writeoff_amount').max = balance;
        document.getElementById('writeoff_amount').value = balance;
    } else {
        document.getElementById('customer_id').value = '';
        document.getElementById('available_balance').textContent = '0';
        document.getElementById('writeoff_amount').value = '';
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>