<?php
/**
 * Loan Module Dashboard
 * Mkumbi Investments ERP System
 * FIXED: Null safety for formatCurrency() calls
 */

define('APP_ACCESS', true);
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

// Get employee info
$employee = getOrCreateEmployeeForSuperAdmin($conn, $user_id, $company_id);

// Check user roles
$is_admin = isAdmin($conn, $user_id);
$is_management = isManagement($conn, $user_id);
$is_hr = $is_admin || $is_management;

// Statistics - FIXED: Initialize all values
$stats = ['pending' => 0, 'active' => 0, 'total_disbursed' => 0, 'outstanding' => 0];
try {
    if ($is_hr) {
        // Count pending loans based on role
        $where_clause = "company_id = ? AND status = 'pending'";
        $params = [$company_id];
        
        if ($is_admin && !$is_management) {
            // Admin counts employee loans (non-admin, non-super-admin)
            $where_clause .= " AND employee_id IN (
                SELECT e.employee_id FROM employees e
                WHERE e.company_id = ? AND NOT EXISTS (
                    SELECT 1 FROM user_roles ur
                    JOIN system_roles sr ON ur.role_id = sr.role_id
                    WHERE ur.user_id = e.user_id 
                    AND sr.role_code IN ('COMPANY_ADMIN', 'SUPER_ADMIN')
                )
            )";
            $params[] = $company_id;
        } elseif ($is_management && !$is_admin) {
            // Management counts admin and super admin loans
            $where_clause .= " AND employee_id IN (
                SELECT e.employee_id FROM employees e
                WHERE e.company_id = ? AND EXISTS (
                    SELECT 1 FROM user_roles ur
                    JOIN system_roles sr ON ur.role_id = sr.role_id
                    WHERE ur.user_id = e.user_id 
                    AND sr.role_code IN ('COMPANY_ADMIN', 'SUPER_ADMIN')
                )
            )";
            $params[] = $company_id;
        }
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM employee_loans WHERE $where_clause");
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['pending'] = $result['count'] ?? 0;
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(loan_amount), 0) as disbursed, COALESCE(SUM(total_outstanding), 0) as outstanding 
        FROM employee_loans WHERE company_id = ? AND status IN ('active', 'disbursed')");
    $stmt->execute([$company_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['active'] = $row['count'] ?? 0;
    $stats['total_disbursed'] = $row['disbursed'] ?? 0;
    $stats['outstanding'] = $row['outstanding'] ?? 0;
} catch (Exception $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
}

// My loans
$my_loans = [];
if ($employee) {
    try {
        $stmt = $conn->prepare("SELECT el.*, el.loan_number, COALESCE(lt.type_name, lt.loan_type_name) as loan_type_name FROM employee_loans el
            JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
            WHERE el.employee_id = ? ORDER BY el.created_at DESC LIMIT 5");
        $stmt->execute([$employee['employee_id']]);
        $my_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching my loans: " . $e->getMessage());
    }
}

// Loan types (matching exact schema)
$loan_types = [];
try {
    $stmt = $conn->prepare("SELECT *, type_name as loan_type_name, max_term_months
                            FROM loan_types WHERE company_id = ? AND is_active = 1");
    $stmt->execute([$company_id]);
    $loan_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching loan types: " . $e->getMessage());
}

$page_title = 'Loan Management';
require_once '../../includes/header.php';
?>

<style>
.stats-card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,0.08);border-left:4px solid;transition:transform .2s}
.stats-card:hover{transform:translateY(-4px)}
.stats-card.primary{border-left-color:#007bff}.stats-card.success{border-left-color:#28a745}
.stats-card.warning{border-left-color:#ffc107}.stats-card.danger{border-left-color:#dc3545}
.stats-number{font-size:2rem;font-weight:700;color:#2c3e50}
.stats-label{color:#6c757d;font-size:.875rem;font-weight:500}
.action-card{background:white;border-radius:12px;padding:2rem;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.08);transition:all .3s;text-decoration:none;color:inherit;display:block}
.action-card:hover{transform:translateY(-5px);box-shadow:0 8px 25px rgba(0,0,0,0.15)}
.action-card i{font-size:2.5rem;color:#007bff;margin-bottom:1rem}
.table-card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,0.08)}
.status-badge{padding:.35rem .75rem;border-radius:20px;font-size:.8rem;font-weight:600}
.status-badge.pending{background:#fff3cd;color:#856404}
.status-badge.approved{background:#cce5ff;color:#004085}
.status-badge.active,.status-badge.disbursed{background:#d4edda;color:#155724}
.status-badge.completed{background:#d1ecf1;color:#0c5460}
.status-badge.rejected{background:#f8d7da;color:#721c24}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-hand-holding-usd text-primary me-2"></i>
                    Loan Management
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Manage employee loans and applications
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="apply.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> Apply for Loan
                    </a>
                    <?php if ($is_hr): ?>
                    <a href="approvals.php" class="btn btn-outline-primary">
                        <i class="fas fa-clipboard-check me-1"></i> Approvals
                    </a>
                    <a href="loan-types.php" class="btn btn-outline-secondary">
                        <i class="fas fa-cog me-1"></i> Loan Types
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
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

    <!-- FIXED: Safe null checking for statistics -->
    <div class="row g-3 mb-4">
        <?php if ($is_hr): ?>
        <div class="col-lg-3 col-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= (int)($stats['pending'] ?? 0) ?></div>
                <div class="stats-label">Pending Applications</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-lg-3 col-6">
            <div class="stats-card success">
                <div class="stats-number"><?= (int)($stats['active'] ?? 0) ?></div>
                <div class="stats-label">Active Loans</div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="stats-card primary">
                <div class="stats-number">TSH <?= number_format(($stats['total_disbursed'] ?? 0)/1000000, 1) ?>M</div>
                <div class="stats-label">Total Disbursed</div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="stats-card danger">
                <div class="stats-number">TSH <?= number_format(($stats['outstanding'] ?? 0)/1000000, 1) ?>M</div>
                <div class="stats-label">Outstanding</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><a href="apply.php" class="action-card"><i class="fas fa-file-signature"></i><h5>Apply for Loan</h5><p class="text-muted small mb-0">Submit application</p></a></div>
        <div class="col-md-3"><a href="my-loans.php" class="action-card"><i class="fas fa-history"></i><h5>My Loans</h5><p class="text-muted small mb-0">View loan history</p></a></div>
        <?php if ($is_hr): ?>
        <div class="col-md-3"><a href="approvals.php" class="action-card"><i class="fas fa-clipboard-check"></i><h5>Approvals</h5><p class="text-muted small mb-0">Review applications</p></a></div>
        <div class="col-md-3"><a href="loan-types.php" class="action-card"><i class="fas fa-cog"></i><h5>Loan Types</h5><p class="text-muted small mb-0">Configure products</p></a></div>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="table-card">
                <h5 class="mb-3"><i class="fas fa-list me-2"></i>My Loans</h5>
                <?php if (empty($my_loans)): ?>
                <p class="text-muted text-center py-4">No loan applications found.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light"><tr><th>Reference</th><th>Type</th><th>Amount</th><th>Outstanding</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($my_loans as $loan): ?>
                            <tr onclick="window.location.href='view.php?id=<?= $loan['loan_id'] ?>'" style="cursor: pointer;">
                                <td><code><?= htmlspecialchars($loan['loan_number'] ?? 'N/A') ?></code></td>
                                <td><?= htmlspecialchars($loan['loan_type_name'] ?? 'Unknown') ?></td>
                                <td>TSH <?= number_format($loan['loan_amount'] ?? 0) ?></td>
                                <td class="text-danger">TSH <?= number_format($loan['total_outstanding'] ?? 0) ?></td>
                                <td><?php echo getStatusBadge($loan['status'] ?? 'unknown'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="table-card">
                <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Available Loan Products</h5>
                <?php if (empty($loan_types)): ?>
                <p class="text-muted">No loan products available.</p>
                <?php else: ?>
                <?php foreach ($loan_types as $lt): ?>
                <div class="border rounded p-3 mb-2">
                    <h6 class="mb-1"><?= htmlspecialchars($lt['loan_type_name'] ?? 'Unknown') ?></h6>
                    <small class="text-muted">
                        Rate: <?= htmlspecialchars($lt['interest_rate'] ?? '0') ?>% | 
                        Max: TSH <?= number_format($lt['max_amount'] ?? 0) ?> | 
                        Term: <?= htmlspecialchars($lt['max_term_months'] ?? '0') ?> months
                    </small>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
