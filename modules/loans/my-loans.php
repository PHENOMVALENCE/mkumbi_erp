<?php
/**
 * My Loans History
 * Mkumbi Investments ERP System
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

$employee = getEmployeeByUserId($conn, $user_id, $company_id);
if (!$employee) {
    $_SESSION['error_message'] = "Employee record not found.";
    header('Location: index.php');
    exit;
}

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$year_filter = $_GET['year'] ?? date('Y');

// Get all loans for this employee
$sql = "SELECT el.*, COALESCE(lt.type_name, lt.loan_type_name) as loan_type_name, lt.interest_rate,
               (SELECT COUNT(*) FROM loan_payments lp WHERE lp.loan_id = el.loan_id) as payments_count,
               (SELECT SUM(total_paid) FROM loan_payments lp WHERE lp.loan_id = el.loan_id) as total_paid
        FROM employee_loans el
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        WHERE el.employee_id = ?";
$params = [$employee['employee_id']];

if ($status_filter) {
    $sql .= " AND el.status = ?";
    $params[] = $status_filter;
}
if ($year_filter) {
    $sql .= " AND YEAR(el.created_at) = ?";
    $params[] = $year_filter;
}

$sql .= " ORDER BY el.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$sql = "SELECT 
            COUNT(*) as total_loans,
            SUM(CASE WHEN status IN ('disbursed', 'active') THEN loan_amount ELSE 0 END) as active_borrowed,
            SUM(CASE WHEN status IN ('disbursed', 'active') THEN total_outstanding ELSE 0 END) as total_outstanding,
            SUM(CASE WHEN status = 'completed' THEN loan_amount ELSE 0 END) as completed_loans
        FROM employee_loans WHERE employee_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$employee['employee_id']]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Get available years
$sql = "SELECT DISTINCT YEAR(created_at) as year FROM employee_loans WHERE employee_id = ? ORDER BY year DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$employee['employee_id']]);
$years = $stmt->fetchAll(PDO::FETCH_COLUMN);

$page_title = "My Loans";
require_once '../../includes/header.php';
?>

<style>
    .summary-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 20px;
        text-align: center;
        height: 100%;
    }
    .summary-card h4 { color: #667eea; margin-bottom: 5px; }
    .summary-card p { color: #6c757d; margin: 0; font-size: 0.9rem; }
    
    .loan-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 25px;
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    .loan-card:hover { transform: translateY(-3px); }
    
    .loan-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid #eee;
    }
    
    .loan-amount { font-size: 1.5rem; font-weight: 700; color: #667eea; }
    
    .loan-details { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
    .loan-detail { text-align: center; }
    .loan-detail small { display: block; color: #6c757d; margin-bottom: 3px; }
    .loan-detail strong { font-size: 1rem; }
    
    .progress-section { margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; }
    .progress { height: 10px; border-radius: 5px; }
    
    .filter-section {
        background: white;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-history me-2"></i>My Loans</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                        <li class="breadcrumb-item active">My Loans</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="summary-card">
                        <h4><?php echo $summary['total_loans']; ?></h4>
                        <p>Total Loans</p>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="summary-card">
                        <h4><?php echo formatCurrency($summary['active_borrowed']); ?></h4>
                        <p>Active Borrowed</p>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="summary-card">
                        <h4 class="text-danger"><?php echo formatCurrency($summary['total_outstanding']); ?></h4>
                        <p>Outstanding Balance</p>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="summary-card">
                        <h4 class="text-success"><?php echo formatCurrency($summary['completed_loans']); ?></h4>
                        <p>Completed Loans</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <?php foreach ($years as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="disbursed" <?php echo $status_filter === 'disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Filter</button>
                        <a href="my-loans.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                    <div class="col-md-3 text-end">
                        <a href="apply.php" class="btn btn-success"><i class="fas fa-plus me-2"></i>New Loan</a>
                    </div>
                </form>
            </div>

            <!-- Loans List -->
            <?php if (empty($loans)): ?>
            <div class="text-center py-5">
                <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                <h5 class="text-muted">No loan applications found</h5>
                <p class="text-muted">You haven't applied for any loans yet.</p>
                <a href="apply.php" class="btn btn-primary mt-2">
                    <i class="fas fa-plus me-2"></i>Apply for Loan
                </a>
            </div>
            <?php else: ?>
            <?php foreach ($loans as $loan): 
                $paid_amount = $loan['total_paid'] ?? 0;
                $paid_percent = $loan['total_repayable'] > 0 ? ($paid_amount / $loan['total_repayable']) * 100 : 0;
            ?>
            <div class="loan-card">
                <div class="loan-header">
                    <div>
                        <span class="loan-amount"><?php echo formatCurrency($loan['loan_amount']); ?></span>
                        <span class="text-muted ms-2"><?php echo htmlspecialchars($loan['loan_type_name']); ?></span>
                        <br>
                        <small class="text-muted">Ref: <?php echo htmlspecialchars($loan['loan_number']); ?></small>
                    </div>
                    <div class="text-end">
                        <?php echo getStatusBadge($loan['status']); ?>
                        <br>
                        <small class="text-muted">Applied: <?php echo date('M d, Y', strtotime($loan['created_at'])); ?></small>
                    </div>
                </div>
                
                <div class="loan-details">
                    <div class="loan-detail">
                        <small>Interest Rate</small>
                        <strong><?php echo $loan['interest_rate']; ?>%</strong>
                    </div>
                    <div class="loan-detail">
                        <small>Term</small>
                        <strong><?php echo $loan['repayment_period_months']; ?> months</strong>
                    </div>
                    <div class="loan-detail">
                        <small>Monthly Payment</small>
                        <strong><?php echo formatCurrency($loan['monthly_deduction']); ?></strong>
                    </div>
                    <div class="loan-detail">
                        <small>Outstanding</small>
                        <strong class="text-danger"><?php echo formatCurrency($loan['total_outstanding']); ?></strong>
                    </div>
                </div>
                
                <?php if (in_array(strtolower($loan['status']), ['disbursed', 'active', 'completed'])): ?>
                <div class="progress-section">
                    <div class="d-flex justify-content-between mb-2">
                        <small>Repayment Progress</small>
                        <small><?php echo round($paid_percent); ?>% paid</small>
                    </div>
                    <div class="progress">
                        <div class="progress-bar bg-success" style="width: <?php echo $paid_percent; ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-muted">Paid: <?php echo formatCurrency($paid_amount); ?></small>
                        <small class="text-muted">Remaining: <?php echo formatCurrency($loan['total_outstanding']); ?></small>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($loan['status'] === 'REJECTED' && $loan['rejection_reason']): ?>
                <div class="alert alert-danger mt-3 mb-0">
                    <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($loan['rejection_reason']); ?>
                </div>
                <?php endif; ?>
                
                <div class="mt-3 pt-3 border-top d-flex justify-content-between">
                    <div>
                        <a href="view.php?id=<?php echo $loan['loan_id']; ?>" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye me-1"></i>View Details
                        </a>
                        <?php if (in_array(strtolower($loan['status']), ['disbursed', 'active'])): ?>
                        <a href="view.php?id=<?php echo $loan['loan_id']; ?>" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-calendar me-1"></i>View Schedule
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php if (strtolower($loan['status']) === 'pending'): ?>
                    <form method="POST" action="process.php" onsubmit="return confirm('Cancel this loan application?');">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="loan_id" value="<?php echo $loan['loan_id']; ?>">
                        <input type="hidden" name="redirect" value="my-loans.php">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-times me-1"></i>Cancel Application
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once '../../includes/footer.php'; ?>
