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

// ==================== STATISTICS ====================
$stats = [
    'total_creditors' => 0,
    'total_land_value' => 0,
    'total_paid' => 0,
    'total_balance' => 0,
    'fully_paid_count' => 0,
    'pending_count' => 0
];

// ==================== PROJECTS FOR FILTER ====================
$projects = [];
try {
    $stmt = $conn->prepare("
        SELECT p.project_id, p.project_name, p.project_code,
        (SELECT COUNT(*) FROM project_sellers WHERE project_id = p.project_id AND company_id = ?) as seller_count
        FROM projects p
        WHERE p.company_id = ? AND p.is_active = 1 
        ORDER BY p.project_name
    ");
    $stmt->execute([$company_id, $company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Projects error: " . $e->getMessage());
}

// ==================== BUILD FILTERS ====================
$where = ['ps.company_id = ?'];
$params = [$company_id];

if (!empty($_GET['project_id'])) {
    $where[] = 'ps.project_id = ?';
    $params[] = (int)$_GET['project_id'];
}
if (!empty($_GET['status'])) {
    if ($_GET['status'] === 'paid') {
        $where[] = '(ps.purchase_amount - COALESCE((
            SELECT SUM(pst.amount_paid)
            FROM project_statements pst
            WHERE pst.project_id = ps.project_id
              AND pst.company_id = ps.company_id
              AND pst.status = "paid"
        ), 0)) <= 0';
    } elseif ($_GET['status'] === 'pending') {
        $where[] = '(ps.purchase_amount - COALESCE((
            SELECT SUM(pst.amount_paid)
            FROM project_statements pst
            WHERE pst.project_id = ps.project_id
              AND pst.company_id = ps.company_id
              AND pst.status = "paid"
        ), 0)) > 0';
    }
}
if (!empty($_GET['search'])) {
    $s = '%' . trim($_GET['search']) . '%';
    $where[] = '(ps.seller_name LIKE ? OR ps.seller_phone LIKE ? OR ps.seller_nida LIKE ? OR p.project_name LIKE ?)';
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// ==================== FETCH CREDITORS ====================
$creditors = [];
try {
    $query = "
        SELECT 
            ps.seller_id,
            ps.project_id,
            ps.seller_name,
            ps.seller_phone,
            ps.seller_nida,
            ps.seller_tin,
            ps.purchase_date,
            ps.purchase_amount,
            COALESCE(p.project_name, CONCAT('Project #', ps.project_id)) AS project_name,
            COALESCE(p.project_code, 'N/A') AS project_code,
            p.region_name,
            p.district_name,
            
            COALESCE((
                SELECT SUM(pst.amount_paid)
                FROM project_statements pst
                WHERE pst.project_id = ps.project_id
                  AND pst.company_id = ps.company_id
                  AND pst.status = 'paid'
            ), 0) AS total_paid
            
        FROM project_sellers ps
        LEFT JOIN projects p ON ps.project_id = p.project_id
        $where_clause
        ORDER BY ps.seller_name
        LIMIT 1000
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $creditors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    foreach ($creditors as $c) {
        $amount = floatval($c['purchase_amount']);
        $paid = floatval($c['total_paid'] ?? 0);
        $balance = $amount - $paid;
        
        $stats['total_land_value'] += $amount;
        $stats['total_paid'] += $paid;
        $stats['total_balance'] += $balance;
        
        if ($balance <= 0) {
            $stats['fully_paid_count']++;
        } else {
            $stats['pending_count']++;
        }
    }
    
    $stats['total_creditors'] = count($creditors);

} catch (Exception $e) {
    error_log("Creditors error: " . $e->getMessage());
}

$page_title = 'Project Creditors Management';
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

/* Table Cell Specific Styling */
.seller-name {
    font-weight: 700;
    color: #2c3e50;
    font-size: 0.9rem;
}

.seller-nida {
    display: block;
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.1rem;
}

.project-info {
    line-height: 1.3;
}

.project-code {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 0.1rem;
}

.project-name {
    font-weight: 500;
    color: #2c3e50;
    font-size: 0.85rem;
}

.contact-info {
    line-height: 1.4;
    font-size: 0.82rem;
}

.contact-item {
    color: #6c757d;
    margin-bottom: 0.2rem;
}

.contact-item i {
    width: 14px;
    text-align: center;
    margin-right: 4px;
    color: #adb5bd;
}

.amount-value {
    font-weight: 600;
    color: #2c3e50;
    white-space: nowrap;
    font-size: 0.85rem;
}

.amount-paid {
    color: #28a745;
    font-weight: 600;
}

.amount-balance {
    font-weight: 600;
}

.amount-balance.zero {
    color: #28a745;
}

.amount-balance.pending {
    color: #dc3545;
}

.progress-cell {
    min-width: 130px;
}

.mini-progress {
    height: 6px;
    background: #e9ecef;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 0.25rem;
}

.mini-progress-bar {
    height: 100%;
    background: #28a745;
    transition: width 0.3s ease;
}

.mini-progress-bar.warning {
    background: #ffc107;
}

.progress-text {
    font-size: 0.7rem;
    color: #6c757d;
    font-weight: 600;
    text-align: center;
}

/* Action Buttons */
.action-btn {
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
    border-radius: 3px;
    margin-right: 0.2rem;
    margin-bottom: 0.2rem;
    white-space: nowrap;
}

.btn-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.2rem;
}

/* Cards */
.filter-card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

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

/* DataTables Overrides */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    font-size: 0.875rem;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 0.375rem 0.75rem;
    margin: 0 0.125rem;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
    
    .stats-label {
        font-size: 0.7rem;
    }
    
    .btn-actions {
        flex-direction: column;
    }
    
    .action-btn {
        margin-right: 0;
        width: 100%;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">Project Creditors Management</h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="create.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i>Add New Seller
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Statistics Cards -->
    <div class="row g-2 mb-3">
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($stats['total_creditors']) ?></div>
                <div class="stats-label">Total Creditors</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card purple">
                <div class="stats-number"><?= number_format($stats['total_land_value']/1000000, 1) ?>M</div>
                <div class="stats-label">Land Value (TSH)</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($stats['total_paid']/1000000, 1) ?>M</div>
                <div class="stats-label">Amount Paid (TSH)</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($stats['total_balance']/1000000, 1) ?>M</div>
                <div class="stats-label">Balance Due (TSH)</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format($stats['fully_paid_count']) ?></div>
                <div class="stats-label">Fully Paid</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($stats['pending_count']) ?></div>
                <div class="stats-label">Pending</div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card filter-card mb-3">
        <div class="card-body">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted mb-1">SEARCH</label>
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Seller name, phone, NIDA, or project..." 
                           value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">PROJECT</label>
                    <select name="project_id" class="form-select form-select-sm">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['project_id'] ?>" 
                                    <?= ($_GET['project_id'] ?? '') == $p['project_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['project_name']) ?> (<?= $p['seller_count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">PAYMENT STATUS</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="paid" <?= ($_GET['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Fully Paid</option>
                        <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card main-card">
        <div class="card-body">
            <?php if (empty($creditors)): ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <p>No project creditors found matching your criteria</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="creditorsTable">
                        <thead>
                            <tr>
                                <th>Seller Name</th>
                                <th>Project</th>
                                <th>Contact Info</th>
                                <th class="text-end">Purchase Amount</th>
                                <th class="text-end">Amount Paid</th>
                                <th class="text-end">Balance Due</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($creditors as $c): 
                                $amount = floatval($c['purchase_amount']);
                                $paid = floatval($c['total_paid'] ?? 0);
                                $balance = $amount - $paid;
                                $percent = $amount > 0 ? ($paid / $amount) * 100 : 0;
                                $fully_paid = $balance <= 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="seller-name"><?= htmlspecialchars($c['seller_name']) ?></div>
                                        <?php if (!empty($c['seller_nida'])): ?>
                                            <span class="seller-nida">NIDA: <?= htmlspecialchars($c['seller_nida']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <div class="project-info">
                                            <span class="project-code"><?= htmlspecialchars($c['project_code']) ?></span>
                                            <span class="project-name"><?= htmlspecialchars($c['project_name']) ?></span>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <div class="contact-info">
                                            <?php if (!empty($c['seller_phone'])): ?>
                                                <div class="contact-item">
                                                    <i class="fas fa-phone"></i><?= htmlspecialchars($c['seller_phone']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($c['purchase_date']) && $c['purchase_date'] !== '0000-00-00'): ?>
                                                <div class="contact-item">
                                                    <i class="fas fa-calendar"></i><?= date('d M Y', strtotime($c['purchase_date'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($c['region_name']) || !empty($c['district_name'])): ?>
                                                <div class="contact-item">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <?php 
                                                    $location = array_filter([$c['district_name'], $c['region_name']]);
                                                    echo htmlspecialchars(implode(', ', $location));
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <td class="text-end amount-value">
                                        <?= number_format($amount, 0) ?>
                                    </td>
                                    
                                    <td class="text-end amount-paid">
                                        <?= number_format($paid, 0) ?>
                                    </td>
                                    
                                    <td class="text-end amount-balance <?= $fully_paid ? 'zero' : 'pending' ?>">
                                        <?= number_format($balance, 0) ?>
                                    </td>
                                    
                                    <td class="progress-cell">
                                        <div class="mini-progress">
                                            <div class="mini-progress-bar <?= $fully_paid ? '' : 'warning' ?>" 
                                                 style="width: <?= min($percent, 100) ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?= number_format($percent, 1) ?>%</div>
                                    </td>
                                    
                                    <td>
                                        <span class="status-badge <?= $fully_paid ? 'paid' : 'pending' ?>">
                                            <?= $fully_paid ? 'Fully Paid' : 'Pending' ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <div class="btn-actions">
                                            <a href="statements.php?project_id=<?= $c['project_id'] ?>" 
                                               class="btn btn-sm btn-outline-primary action-btn" 
                                               title="Payment Statements">
                                                <i class="fas fa-file-invoice-dollar"></i> Statements
                                            </a>
                                            <a href="view.php?id=<?= $c['project_id'] ?>" 
                                               class="btn btn-sm btn-outline-info action-btn" 
                                               title="View Project">
                                                <i class="fas fa-eye"></i> Project
                                            </a>
                                        </div>
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
                    $('#creditorsTable').DataTable({
                        pageLength: 25,
                        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                        responsive: true,
                        order: [[0, 'asc']],
                        columnDefs: [
                            { 
                                targets: [3, 4, 5],
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
                            info: "Showing _START_ to _END_ of _TOTAL_ creditors",
                            infoEmpty: "Showing 0 to 0 of 0 creditors",
                            infoFiltered: "(filtered from _MAX_ total creditors)",
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

<?php require_once '../../includes/footer.php'; ?>