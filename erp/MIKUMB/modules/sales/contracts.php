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

// Fetch statistics
try {
    $stats_query = "
        SELECT 
            COUNT(*) as total_contracts,
            COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) as draft_contracts,
            COALESCE(SUM(CASE WHEN status = 'pending_signature' THEN 1 ELSE 0 END), 0) as pending_contracts,
            COALESCE(SUM(CASE WHEN status = 'signed' THEN 1 ELSE 0 END), 0) as signed_contracts,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) as completed_contracts,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled_contracts
        FROM plot_contracts
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure all values are integers, not null
    $stats = [
        'total_contracts' => (int)($stats['total_contracts'] ?? 0),
        'draft_contracts' => (int)($stats['draft_contracts'] ?? 0),
        'pending_contracts' => (int)($stats['pending_contracts'] ?? 0),
        'signed_contracts' => (int)($stats['signed_contracts'] ?? 0),
        'completed_contracts' => (int)($stats['completed_contracts'] ?? 0),
        'cancelled_contracts' => (int)($stats['cancelled_contracts'] ?? 0)
    ];
} catch (PDOException $e) {
    error_log("Error fetching contract stats: " . $e->getMessage());
    $stats = [
        'total_contracts' => 0, 
        'draft_contracts' => 0, 
        'pending_contracts' => 0, 
        'signed_contracts' => 0,
        'completed_contracts' => 0,
        'cancelled_contracts' => 0
    ];
}

// Build filter conditions
$where_conditions = ["pc.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['status'])) {
    $where_conditions[] = "pc.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['contract_type'])) {
    $where_conditions[] = "pc.contract_type = ?";
    $params[] = $_GET['contract_type'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(pc.contract_number LIKE ? OR c.full_name LIKE ? OR p.plot_number LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch contracts
try {
    $contracts_query = "
        SELECT pc.*,
               r.reservation_id, r.reservation_number, r.total_amount,
               c.customer_id, c.full_name as customer_name,
               COALESCE(c.phone, c.phone1) as customer_phone,
               c.email as customer_email,
               p.plot_id, p.plot_number, p.block_number, p.area,
               pr.project_name, pr.project_code
        FROM plot_contracts pc
        INNER JOIN reservations r ON pc.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects pr ON p.project_id = pr.project_id
        WHERE $where_clause
        ORDER BY pc.created_at DESC
    ";
    $stmt = $conn->prepare($contracts_query);
    $stmt->execute($params);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching contracts: " . $e->getMessage());
    $contracts = [];
}

$page_title = 'Plot Contracts Management';
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
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.secondary { border-left-color: #6c757d; }

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

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.table-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.draft {
    background: #e7e8ea;
    color: #495057;
}

.status-badge.pending_signature {
    background: #fff3cd;
    color: #856404;
}

.status-badge.signed {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.completed {
    background: #d4edda;
    color: #155724;
}

.status-badge.cancelled {
    background: #f8d7da;
    color: #721c24;
}

.contract-type-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.contract-type-badge.sale {
    background: #e7f3ff;
    color: #0056b3;
}

.contract-type-badge.lease {
    background: #fff4e6;
    color: #cc7a00;
}

.contract-type-badge.installment {
    background: #f0e6ff;
    color: #6f42c1;
}

.contract-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.document-icon {
    color: #007bff;
    font-size: 1.2rem;
}

.contract-timeline {
    font-size: 0.75rem;
    color: #6c757d;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-contract text-primary me-2"></i>Plot Contracts
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage plot sale and lease contracts</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Sales
                    </a>
                    <a href="contract-create.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> New Contract
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($stats['total_contracts']); ?></div>
                    <div class="stats-label">Total</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card secondary">
                    <div class="stats-number"><?php echo number_format($stats['draft_contracts']); ?></div>
                    <div class="stats-label">Draft</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($stats['pending_contracts']); ?></div>
                    <div class="stats-label">Pending</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format($stats['signed_contracts']); ?></div>
                    <div class="stats-label">Signed</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($stats['completed_contracts']); ?></div>
                    <div class="stats-label">Completed</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format($stats['cancelled_contracts']); ?></div>
                    <div class="stats-label">Cancelled</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Contract #, Customer, Plot..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Contract Type</label>
                    <select name="contract_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="sale" <?php echo (isset($_GET['contract_type']) && $_GET['contract_type'] == 'sale') ? 'selected' : ''; ?>>Sale</option>
                        <option value="lease" <?php echo (isset($_GET['contract_type']) && $_GET['contract_type'] == 'lease') ? 'selected' : ''; ?>>Lease</option>
                        <option value="installment" <?php echo (isset($_GET['contract_type']) && $_GET['contract_type'] == 'installment') ? 'selected' : ''; ?>>Installment</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo (isset($_GET['status']) && $_GET['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending_signature" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending_signature') ? 'selected' : ''; ?>>Pending Signature</option>
                        <option value="signed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'signed') ? 'selected' : ''; ?>>Signed</option>
                        <option value="completed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-1"></i> Filter
                    </button>
                    <a href="contracts.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Contracts Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="contractsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Contract #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Plot</th>
                            <th>Type</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Documents</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contracts)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-file-contract fa-3x mb-3 d-block"></i>
                                <p class="mb-2">No contracts found.</p>
                                <a href="contract-create.php" class="btn btn-primary btn-sm">
                                    <i class="fas fa-plus me-1"></i> Create Your First Contract
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($contracts as $contract): ?>
                        <tr>
                            <td>
                                <span class="contract-number">
                                    <?php echo htmlspecialchars($contract['contract_number']); ?>
                                </span>
                                <div class="contract-timeline mt-1">
                                    <small>Reservation: <?php echo htmlspecialchars($contract['reservation_number']); ?></small>
                                </div>
                            </td>
                            <td>
                                <i class="fas fa-calendar text-primary me-1"></i>
                                <?php echo date('M d, Y', strtotime($contract['contract_date'])); ?>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($contract['customer_name']); ?></div>
                                <?php if (!empty($contract['customer_phone'])): ?>
                                <small class="text-muted">
                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($contract['customer_phone']); ?>
                                </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold">
                                    Plot <?php echo htmlspecialchars($contract['plot_number']); ?>
                                    <?php if (!empty($contract['block_number'])): ?>
                                        <small>(Block <?php echo htmlspecialchars($contract['block_number']); ?>)</small>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($contract['project_name']); ?> - 
                                    <?php echo number_format($contract['area'], 2); ?> m²
                                </small>
                            </td>
                            <td>
                                <span class="contract-type-badge <?php echo $contract['contract_type']; ?>">
                                    <?php echo ucfirst($contract['contract_type']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($contract['contract_duration_months'])): ?>
                                    <span class="badge bg-secondary">
                                        <?php echo $contract['contract_duration_months']; ?> months
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $contract['status']; ?>">
                                    <?php echo str_replace('_', ' ', ucfirst($contract['status'])); ?>
                                </span>
                                <?php if ($contract['status'] == 'signed' && !empty($contract['signed_date'])): ?>
                                    <div class="contract-timeline mt-1">
                                        <small>Signed: <?php echo date('M d, Y', strtotime($contract['signed_date'])); ?></small>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <?php if (!empty($contract['contract_template_path'])): ?>
                                        <a href="../../<?php echo htmlspecialchars($contract['contract_template_path']); ?>" 
                                           target="_blank" 
                                           class="document-icon"
                                           title="View Template">
                                            <i class="fas fa-file-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($contract['signed_contract_path'])): ?>
                                        <a href="../../<?php echo htmlspecialchars($contract['signed_contract_path']); ?>" 
                                           target="_blank" 
                                           class="document-icon text-success"
                                           title="View Signed Contract">
                                            <i class="fas fa-file-signature"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($contract['contract_template_path']) && empty($contract['signed_contract_path'])): ?>
                                        <span class="text-muted">
                                            <i class="fas fa-file-times"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="contract-view.php?id=<?php echo $contract['contract_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View Contract">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <?php if ($contract['status'] == 'draft' || $contract['status'] == 'pending_signature'): ?>
                                    <a href="contract-edit.php?id=<?php echo $contract['contract_id']; ?>" 
                                       class="btn btn-outline-warning action-btn"
                                       title="Edit Contract">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <a href="contract-print.php?id=<?php echo $contract['contract_id']; ?>" 
                                       class="btn btn-outline-info action-btn"
                                       target="_blank"
                                       title="Print Contract">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary action-btn dropdown-toggle" 
                                                type="button" 
                                                data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php if ($contract['status'] == 'pending_signature'): ?>
                                            <li>
                                                <a class="dropdown-item" 
                                                   href="contract-sign.php?id=<?php echo $contract['contract_id']; ?>">
                                                    <i class="fas fa-signature me-2"></i> Mark as Signed
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($contract['status'] == 'signed'): ?>
                                            <li>
                                                <a class="dropdown-item" 
                                                   href="contract-complete.php?id=<?php echo $contract['contract_id']; ?>">
                                                    <i class="fas fa-check-circle me-2"></i> Mark as Completed
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <li>
                                                <a class="dropdown-item" 
                                                   href="contract-download.php?id=<?php echo $contract['contract_id']; ?>">
                                                    <i class="fas fa-download me-2"></i> Download PDF
                                                </a>
                                            </li>
                                            
                                            <li><hr class="dropdown-divider"></li>
                                            
                                            <?php if ($contract['status'] != 'cancelled' && $contract['status'] != 'completed'): ?>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="contract-cancel.php?id=<?php echo $contract['contract_id']; ?>"
                                                   onclick="return confirm('Are you sure you want to cancel this contract?')">
                                                    <i class="fas fa-ban me-2"></i> Cancel Contract
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    const tableBody = $('#contractsTable tbody tr');
    const hasData = tableBody.length > 0 && !tableBody.first().find('td[colspan]').length;
    
    if (hasData) {
        $('#contractsTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: [-1, -2] }
            ],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search contracts..."
            }
        });
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>