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
            COUNT(*) as total_quotations,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_quotations,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_quotations,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_quotations,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_quotations,
            COALESCE(SUM(total_amount), 0) as total_value,
            COALESCE(SUM(CASE WHEN status = 'accepted' THEN total_amount ELSE 0 END), 0) as accepted_value
        FROM quotations
        WHERE company_id = ? AND is_active = 1
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Calculate acceptance rate
    $total = (int)$stats['total_quotations'];
    $accepted = (int)$stats['accepted_quotations'];
    $acceptance_rate = $total > 0 ? ($accepted / $total) * 100 : 0;
} catch (PDOException $e) {
    error_log("Error fetching quotation stats: " . $e->getMessage());
    $stats = [
        'total_quotations' => 0,
        'draft_quotations' => 0,
        'sent_quotations' => 0,
        'accepted_quotations' => 0,
        'rejected_quotations' => 0,
        'total_value' => 0,
        'accepted_value' => 0
    ];
    $acceptance_rate = 0;
}

// Build filter conditions
$where_conditions = ["q.company_id = ? AND q.is_active = 1"];
$params = [$company_id];

if (!empty($_GET['status'])) {
    $where_conditions[] = "q.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "q.quote_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "q.quote_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(q.quotation_number LIKE ? OR c.full_name LIKE ? OR l.company_name LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// ✅ FIXED: Correct JOIN with created_by column
try {
    $quotations_query = "
        SELECT 
            q.*,
            c.full_name as customer_name,
            l.company_name as lead_company,
            u.full_name as prepared_by_name
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.customer_id
        LEFT JOIN leads l ON q.lead_id = l.lead_id
        LEFT JOIN users u ON q.created_by = u.user_id  -- ✅ FIXED: created_by instead of prepared_by
        WHERE $where_clause
        ORDER BY q.quote_date DESC, q.created_at DESC
    ";
    $stmt = $conn->prepare($quotations_query);
    $stmt->execute($params);
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching quotations: " . $e->getMessage());
    $quotations = [];
}

$page_title = 'Quotations';
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
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 1.75rem;
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

.status-badge.draft { background: #e9ecef; color: #495057; }
.status-badge.sent { background: #cfe2ff; color: #084298; }
.status-badge.accepted { background: #d4edda; color: #155724; }
.status-badge.rejected { background: #f8d7da; color: #721c24; }
.status-badge.expired { background: #f8d7da; color: #721c24; }
.status-badge.viewed { background: #d1ecf1; color: #0c5460; }
.status-badge.cancelled { background: #f8d7da; color: #721c24; }

.quote-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
}

.amount-highlight {
    font-weight: 700;
    font-size: 1.1rem;
    color: #28a745;
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.validity-badge {
    background: #fff3cd;
    color: #856404;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
    margin-bottom: 1rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-success me-2"></i>Quotations
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage sales quotations and proposals</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="leads.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-users me-1"></i> Leads
                    </a>
                    <a href="create-quotation.php" class="btn btn-success">
                        <i class="fas fa-plus-circle me-1"></i> New Quotation
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_quotations']); ?></div>
                    <div class="stats-label">Total Quotations</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format((int)$stats['sent_quotations']); ?></div>
                    <div class="stats-label">Sent</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['accepted_quotations']); ?></div>
                    <div class="stats-label">Accepted</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($acceptance_rate, 1); ?>%</div>
                    <div class="stats-label">Accept Rate</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['accepted_value'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Won Value</div>
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
                           placeholder="Quote #, customer, company..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo (isset($_GET['status']) && $_GET['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="sent" <?php echo (isset($_GET['status']) && $_GET['status'] == 'sent') ? 'selected' : ''; ?>>Sent</option>
                        <option value="viewed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'viewed') ? 'selected' : ''; ?>>Viewed</option>
                        <option value="accepted" <?php echo (isset($_GET['status']) && $_GET['status'] == 'accepted') ? 'selected' : ''; ?>>Accepted</option>
                        <option value="rejected" <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                        <option value="expired" <?php echo (isset($_GET['status']) && $_GET['status'] == 'expired') ? 'selected' : ''; ?>>Expired</option>
                        <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Date From</label>
                    <input type="date" 
                           name="date_from" 
                           class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Date To</label>
                    <input type="date" 
                           name="date_to" 
                           class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="quotations.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Quotations Table -->
        <div class="table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2 text-success"></i>
                    Quotations List (<?php echo number_format(count($quotations)); ?>)
                </h5>
                <a href="create-quotation.php" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> New Quotation
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="quotationsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Quote #</th>
                                <th>Customer / Lead</th>
                                <th>Total Amount</th>
                                <th>Valid Until</th>
                                <th>Status</th>
                                <th>Prepared By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($quotations)): ?>
                            <tr>
                                <td colspan="8" class="empty-state">
                                    <i class="fas fa-file-invoice"></i>
                                    <h5>No quotations found</h5>
                                    <p class="mb-3">Get started by creating your first quotation</p>
                                    <a href="create-quotation.php" class="btn btn-success btn-lg">
                                        <i class="fas fa-plus me-2"></i>Create First Quotation
                                    </a>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($quotations as $quote): ?>
                            <?php
                            $isExpired = !empty($quote['valid_until']) && 
                                        strtotime($quote['valid_until']) < time() && 
                                        in_array($quote['status'], ['sent', 'viewed']);
                            $statusClass = $isExpired ? 'expired' : $quote['status'];
                            ?>
                            <tr>
                                <td>
                                    <i class="fas fa-calendar text-success me-1"></i>
                                    <?php echo date('M d, Y', strtotime($quote['quote_date'])); ?>
                                </td>
                                <td>
                                    <span class="quote-number">
                                        <?php echo htmlspecialchars($quote['quotation_number']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($quote['customer_name'])): ?>
                                    <div class="fw-bold"><?php echo htmlspecialchars($quote['customer_name']); ?></div>
                                    <small class="text-muted">Customer</small>
                                    <?php elseif (!empty($quote['lead_company'])): ?>
                                    <div class="fw-bold"><?php echo htmlspecialchars($quote['lead_company']); ?></div>
                                    <small class="text-muted">Lead</small>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="amount-highlight">
                                        TSH <?php echo number_format((float)$quote['total_amount'], 0); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($quote['valid_until'])): ?>
                                    <div><?php echo date('M d, Y', strtotime($quote['valid_until'])); ?></div>
                                    <?php if ($isExpired): ?>
                                    <span class="validity-badge">EXPIRED</span>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($isExpired ? 'Expired' : $quote['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($quote['prepared_by_name'] ?? 'System'); ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="view-quotation.php?id=<?php echo $quote['quotation_id']; ?>" 
                                           class="btn btn-outline-primary action-btn"
                                           title="View Quotation">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="print-quotation.php?id=<?php echo $quote['quotation_id']; ?>" 
                                           class="btn btn-outline-secondary action-btn"
                                           title="Print PDF"
                                           target="_blank">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <?php if ($quote['status'] == 'draft'): ?>
                                        <a href="edit-quotation.php?id=<?php echo $quote['quotation_id']; ?>" 
                                           class="btn btn-outline-warning action-btn"
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (in_array($quote['status'], ['accepted'])): ?>
                                        <a href="../../modules/sales/create-reservation.php?quotation_id=<?php echo $quote['quotation_id']; ?>" 
                                           class="btn btn-outline-success action-btn"
                                           title="Create Reservation">
                                            <i class="fas fa-check-circle"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($quotations)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end">TOTAL VALUE:</th>
                                <th>
                                    <span class="amount-highlight">
                                        TSH <?php echo number_format(array_sum(array_column($quotations, 'total_amount')), 0); ?>
                                    </span>
                                </th>
                                <th colspan="4"></th>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
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
    const table = $('#quotationsTable');
    const hasData = table.find('tbody tr').length > 0 && 
                   !table.find('tbody tr td[colspan]').length;
    
    if (hasData) {
        table.DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }, // Actions column
                { searchable: false, targets: [0, 4, 6] } // Date, Valid Until, Prepared By
            ],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search quotations...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ quotations",
                infoEmpty: "No quotations available",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            },
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
        });
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>