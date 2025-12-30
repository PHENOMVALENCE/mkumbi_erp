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

// Initialize defaults
$stats = ['total_types' => 0, 'active_types' => 0, 'inactive_types' => 0];
$trans_data = [];
$tax_types = [];

// Fetch statistics
try {
    $stats_query = "
        SELECT 
            COUNT(*) as total_types,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_types,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_types
        FROM tax_types
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stats_result) {
        $stats = [
            'total_types' => (int)$stats_result['total_types'],
            'active_types' => (int)$stats_result['active_types'],
            'inactive_types' => (int)$stats_result['inactive_types']
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching tax type stats: " . $e->getMessage());
}

// Check if tax_transactions table exists (optional)
$trans_table_exists = false;
try {
    $result = $conn->query("SHOW TABLES LIKE 'tax_transactions'");
    $trans_table_exists = $result->rowCount() > 0;
    
    if ($trans_table_exists) {
        $trans_query = "
            SELECT 
                tax_type_id,
                COUNT(*) as transaction_count,
                COALESCE(SUM(tax_amount), 0) as total_collected
            FROM tax_transactions
            WHERE company_id = ?
            GROUP BY tax_type_id
        ";
        $stmt = $conn->prepare($trans_query);
        $stmt->execute([$company_id]);
        $trans_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($trans_stats as $row) {
            $trans_data[$row['tax_type_id']] = [
                'count' => (int)$row['transaction_count'],
                'total' => (float)$row['total_collected']
            ];
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching transaction stats: " . $e->getMessage());
}

// Build filter conditions
$where_conditions = ["company_id = ?"];
$params = [$company_id];

if (isset($_GET['status']) && $_GET['status'] !== '') {
    $where_conditions[] = "is_active = ?";
    $params[] = (int)$_GET['status'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(tax_code LIKE ? OR tax_name LIKE ? OR description LIKE ?)";
    $search = '%' . trim($_GET['search']) . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if (!empty($_GET['applies_to']) && $_GET['applies_to'] !== 'all') {
    $where_conditions[] = "applies_to = ?";
    $params[] = $_GET['applies_to'];
}

$where_clause = implode(' AND ', $where_conditions);

// Pagination setup
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Get total records for pagination
try {
    $count_query = "SELECT COUNT(*) FROM tax_types WHERE " . $where_clause;
    $stmt = $conn->prepare($count_query);
    $stmt->execute($params);
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
} catch (PDOException $e) {
    error_log("Error counting tax types: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 0;
}

// Fetch tax types with safe query
try {
    $query = "
        SELECT 
            tt.*,
            COALESCE(u.full_name, 'System') as created_by_name
        FROM tax_types tt
        LEFT JOIN users u ON tt.created_by = u.user_id
        WHERE " . $where_clause . "
        ORDER BY is_active DESC, tax_name ASC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $tax_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching tax types: " . $e->getMessage());
    // Fallback to simple query without JOIN
    try {
        array_pop($params); // Remove offset
        array_pop($params); // Remove per_page
        
        $fallback_query = "
            SELECT * FROM tax_types 
            WHERE " . $where_clause . "
            ORDER BY is_active DESC, tax_name ASC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $stmt = $conn->prepare($fallback_query);
        $stmt->execute($params);
        $tax_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add created_by_name as 'System' for all
        foreach ($tax_types as &$tax) {
            $tax['created_by_name'] = 'System';
        }
    } catch (PDOException $e2) {
        error_log("Fallback query failed: " . $e2->getMessage());
        $tax_types = [];
    }
}

$page_title = 'Tax Types';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
    transition: all 0.3s ease;
}

.stats-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
}

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}

.stats-label {
    font-size: 0.85rem;
    font-weight: 500;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 0.5rem;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.table-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.table {
    margin-bottom: 0;
}

.table thead {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
}

.table thead th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    padding: 1rem 0.75rem;
    white-space: nowrap;
}

.table tbody tr {
    transition: all 0.2s ease;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
    transform: scale(1.01);
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.table tbody td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
}

.tax-code {
    font-family: 'SF Mono', 'Monaco', 'Cascadia Code', 'Roboto Mono', monospace;
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-weight: 600;
    color: #1976d2;
    font-size: 0.85rem;
    border: 1px solid #90caf9;
    display: inline-block;
}

.tax-name {
    font-weight: 600;
    color: #212529;
    font-size: 0.95rem;
}

.tax-rate-badge {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1.1rem;
    display: inline-block;
    border: 1px solid #b1dfbb;
}

.status-badge {
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    display: inline-block;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-badge.inactive {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.applies-to-badge {
    background: #fff3cd;
    color: #856404;
    padding: 0.3rem 0.7rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 500;
    border: 1px solid #ffeeba;
    display: inline-block;
}

.transaction-info {
    font-size: 0.85rem;
}

.transaction-info .count {
    color: #6c757d;
    font-weight: 500;
}

.transaction-info .amount {
    color: #28a745;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 5rem;
    opacity: 0.3;
    margin-bottom: 1.5rem;
}

.btn-action {
    padding: 0.4rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.btn-action:hover {
    transform: translateY(-2px);
}

.pagination-container {
    background: white;
    padding: 1.25rem;
    border-radius: 0 0 12px 12px;
    border-top: 1px solid #dee2e6;
}

.page-info {
    color: #6c757d;
    font-size: 0.9rem;
}

.description-text {
    color: #6c757d;
    font-size: 0.85rem;
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .stats-number {
        font-size: 1.5rem;
    }
    
    .tax-code {
        font-size: 0.75rem;
        padding: 0.3rem 0.6rem;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-percentage text-primary me-2"></i>
                    Tax Types
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Manage tax rates and types (<?php echo number_format($total_records); ?> total)
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="add-type.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> New Tax Type
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
            <?php echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); 
            unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-4 col-md-6">
                <div class="stats-card">
                    <div class="stats-number"><?php echo number_format($stats['total_types']); ?></div>
                    <div class="stats-label">Total Types</div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                    <div class="stats-number"><?php echo number_format($stats['active_types']); ?></div>
                    <div class="stats-label">Active Types</div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%);">
                    <div class="stats-number"><?php echo number_format($stats['inactive_types']); ?></div>
                    <div class="stats-label">Inactive Types</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-4 col-md-6">
                    <label class="form-label fw-semibold small">Search Tax Types</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" 
                               name="search" 
                               class="form-control" 
                               placeholder="Search by code, name, description..."
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold small">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="1" <?php echo (isset($_GET['status']) && $_GET['status'] === '1') ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo (isset($_GET['status']) && $_GET['status'] === '0') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold small">Applies To</label>
                    <select name="applies_to" class="form-select">
                        <option value="">All Types</option>
                        <option value="sales" <?php echo (isset($_GET['applies_to']) && $_GET['applies_to'] === 'sales') ? 'selected' : ''; ?>>Sales</option>
                        <option value="purchases" <?php echo (isset($_GET['applies_to']) && $_GET['applies_to'] === 'purchases') ? 'selected' : ''; ?>>Purchases</option>
                        <option value="services" <?php echo (isset($_GET['applies_to']) && $_GET['applies_to'] === 'services') ? 'selected' : ''; ?>>Services</option>
                        <option value="payroll" <?php echo (isset($_GET['applies_to']) && $_GET['applies_to'] === 'payroll') ? 'selected' : ''; ?>>Payroll</option>
                        <option value="all" <?php echo (isset($_GET['applies_to']) && $_GET['applies_to'] === 'all') ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
                <div class="col-lg-2 col-md-6">
                    <?php if (!empty($_GET['search']) || !empty($_GET['status']) || !empty($_GET['applies_to'])): ?>
                    <a href="?" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times me-1"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Tax Types Table -->
        <?php if (empty($tax_types)): ?>
        <div class="table-container">
            <div class="empty-state">
                <i class="fas fa-percentage"></i>
                <h4 class="mb-3">No Tax Types Found</h4>
                <p class="lead mb-4">
                    <?php if (!empty($_GET['search']) || !empty($_GET['status']) || !empty($_GET['applies_to'])): ?>
                        No tax types match your current filters. Try adjusting your search criteria.
                    <?php else: ?>
                        Start by creating your first tax type for VAT, WHT, or any other tax.
                    <?php endif; ?>
                </p>
                <?php if (empty($_GET['search']) && empty($_GET['status']) && empty($_GET['applies_to'])): ?>
                <a href="add-type.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus-circle me-2"></i>
                    Create First Tax Type
                </a>
                <?php else: ?>
                <a href="?" class="btn btn-outline-primary">
                    <i class="fas fa-times me-1"></i> Clear Filters
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="12%">Tax Code</th>
                            <th width="18%">Tax Name</th>
                            <th width="8%">Rate</th>
                            <th width="12%">Applies To</th>
                            <th width="20%">Description</th>
                            <?php if ($trans_table_exists): ?>
                            <th width="12%">Transactions</th>
                            <?php endif; ?>
                            <th width="8%">Status</th>
                            <th width="5%" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $row_number = $offset + 1;
                        foreach ($tax_types as $tax): 
                        ?>
                        <tr>
                            <td class="text-muted"><?php echo $row_number++; ?></td>
                            <td>
                                <span class="tax-code">
                                    <?php echo htmlspecialchars($tax['tax_code']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="tax-name">
                                    <?php echo htmlspecialchars($tax['tax_name']); ?>
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-user me-1"></i>
                                    <?php echo htmlspecialchars($tax['created_by_name']); ?>
                                </small>
                            </td>
                            <td>
                                <span class="tax-rate-badge">
                                    <?php echo number_format((float)$tax['tax_rate'], 2); ?>%
                                </span>
                            </td>
                            <td>
                                <span class="applies-to-badge">
                                    <i class="fas fa-tag me-1"></i>
                                    <?php echo ucfirst($tax['applies_to'] ?? 'All'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($tax['description'])): ?>
                                <span class="description-text" title="<?php echo htmlspecialchars($tax['description']); ?>">
                                    <?php echo htmlspecialchars($tax['description']); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($trans_table_exists): ?>
                            <td>
                                <?php if (isset($trans_data[$tax['tax_type_id']])): ?>
                                <div class="transaction-info">
                                    <div class="count">
                                        <i class="fas fa-receipt me-1"></i>
                                        <?php echo number_format($trans_data[$tax['tax_type_id']]['count']); ?>
                                    </div>
                                    <div class="amount">
                                        <i class="fas fa-coins me-1"></i>
                                        TSH <?php echo number_format($trans_data[$tax['tax_type_id']]['total'], 0); ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small">No transactions</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <span class="status-badge <?php echo $tax['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $tax['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="edit-type.php?id=<?php echo $tax['tax_type_id']; ?>" 
                                       class="btn btn-outline-primary btn-action"
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($trans_table_exists && isset($trans_data[$tax['tax_type_id']])): ?>
                                    <a href="transactions.php?tax_type=<?php echo $tax['tax_type_id']; ?>" 
                                       class="btn btn-outline-info btn-action"
                                       title="View Transactions">
                                        <i class="fas fa-receipt"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="page-info">
                            Showing <?php echo number_format($offset + 1); ?> to 
                            <?php echo number_format(min($offset + $per_page, $total_records)); ?> 
                            of <?php echo number_format($total_records); ?> entries
                        </div>
                    </div>
                    <div class="col-md-6">
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-sm justify-content-end mb-0">
                                <!-- Previous -->
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" 
                                       href="?page=<?php echo ($page - 1); ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''; ?><?php echo !empty($_GET['applies_to']) ? '&applies_to=' . urlencode($_GET['applies_to']) : ''; ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1<?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''; ?><?php echo !empty($_GET['applies_to']) ? '&applies_to=' . urlencode($_GET['applies_to']) : ''; ?>">1</a>
                                    </li>
                                    <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" 
                                       href="?page=<?php echo $i; ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''; ?><?php echo !empty($_GET['applies_to']) ? '&applies_to=' . urlencode($_GET['applies_to']) : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''; ?><?php echo !empty($_GET['applies_to']) ? '&applies_to=' . urlencode($_GET['applies_to']) : ''; ?>"><?php echo $total_pages; ?></a>
                                    </li>
                                <?php endif; ?>
                                
                                <!-- Next -->
                                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" 
                                       href="?page=<?php echo ($page + 1); ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''; ?><?php echo !empty($_GET['applies_to']) ? '&applies_to=' . urlencode($_GET['applies_to']) : ''; ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($_GET['search']) || !empty($_GET['status']) || !empty($_GET['applies_to'])): ?>
        <div class="alert alert-info mt-3">
            <i class="fas fa-info-circle me-2"></i>
            Showing filtered results: <strong><?php echo number_format($total_records); ?></strong> of <strong><?php echo number_format($stats['total_types']); ?></strong> total tax types
            <a href="?" class="btn btn-sm btn-outline-primary float-end">View All</a>
        </div>
        <?php endif; ?>

        <?php endif; ?>

    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add loading state to filter button
    const filterForm = document.querySelector('form[method="GET"]');
    const filterBtn = filterForm?.querySelector('button[type="submit"]');
    
    if (filterForm && filterBtn) {
        filterForm.addEventListener('submit', function() {
            filterBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Filtering...';
            filterBtn.disabled = true;
        });
    }
    
    // Add tooltips to description cells
    const descriptionCells = document.querySelectorAll('.description-text');
    descriptionCells.forEach(cell => {
        if (cell.scrollWidth > cell.clientWidth) {
            cell.style.cursor = 'help';
        }
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>