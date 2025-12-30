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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'create_budget') {
            // Validate required fields
            if (empty($_POST['budget_name']) || empty($_POST['fiscal_year']) || empty($_POST['start_date']) || empty($_POST['end_date'])) {
                throw new Exception('Budget name, fiscal year, and dates are required');
            }
            
            // Insert budget
            $stmt = $conn->prepare("
                INSERT INTO budgets (
                    company_id, budget_name, budget_year, budget_period, fiscal_year,
                    start_date, end_date, total_amount, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW())
            ");
            
            $budget_year = date('Y', strtotime($_POST['start_date']));
            
            $stmt->execute([
                $company_id,
                $_POST['budget_name'],
                $budget_year,
                $_POST['budget_period'],
                $_POST['fiscal_year'],
                $_POST['start_date'],
                $_POST['end_date'],
                $_POST['total_amount'] ?? 0,
                $user_id
            ]);
            
            $budget_id = $conn->lastInsertId();
            
            echo json_encode(['success' => true, 'message' => 'Budget created successfully', 'budget_id' => $budget_id]);
            
        } elseif ($_POST['action'] === 'update_budget') {
            if (empty($_POST['budget_id'])) {
                throw new Exception('Budget ID is required');
            }
            
            $stmt = $conn->prepare("
                UPDATE budgets SET 
                    budget_name = ?,
                    budget_period = ?,
                    fiscal_year = ?,
                    start_date = ?,
                    end_date = ?,
                    total_amount = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE budget_id = ? AND company_id = ?
            ");
            
            $stmt->execute([
                $_POST['budget_name'],
                $_POST['budget_period'],
                $_POST['fiscal_year'],
                $_POST['start_date'],
                $_POST['end_date'],
                $_POST['total_amount'] ?? 0,
                $user_id,
                $_POST['budget_id'],
                $company_id
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Budget updated successfully']);
            
        } elseif ($_POST['action'] === 'delete_budget') {
            if (empty($_POST['budget_id'])) {
                throw new Exception('Budget ID is required');
            }
            
            // Check if budget has lines
            $check = $conn->prepare("SELECT COUNT(*) as count FROM budget_lines WHERE budget_id = ?");
            $check->execute([$_POST['budget_id']]);
            $result = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('Cannot delete budget with existing budget lines');
            }
            
            $stmt = $conn->prepare("DELETE FROM budgets WHERE budget_id = ? AND company_id = ?");
            $stmt->execute([$_POST['budget_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Budget deleted successfully']);
            
        } elseif ($_POST['action'] === 'get_budget') {
            if (empty($_POST['budget_id'])) {
                throw new Exception('Budget ID is required');
            }
            
            $stmt = $conn->prepare("SELECT * FROM budgets WHERE budget_id = ? AND company_id = ?");
            $stmt->execute([$_POST['budget_id'], $company_id]);
            $budget = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$budget) {
                throw new Exception('Budget not found');
            }
            
            echo json_encode(['success' => true, 'budget' => $budget]);
            
        } elseif ($_POST['action'] === 'approve_budget') {
            if (empty($_POST['budget_id'])) {
                throw new Exception('Budget ID is required');
            }
            
            $stmt = $conn->prepare("
                UPDATE budgets 
                SET status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE budget_id = ? AND company_id = ?
            ");
            
            $stmt->execute([$user_id, $_POST['budget_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Budget approved successfully']);
            
        } elseif ($_POST['action'] === 'activate_budget') {
            if (empty($_POST['budget_id'])) {
                throw new Exception('Budget ID is required');
            }
            
            // Check if budget is approved
            $check = $conn->prepare("SELECT status FROM budgets WHERE budget_id = ? AND company_id = ?");
            $check->execute([$_POST['budget_id'], $company_id]);
            $budget = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($budget['status'] !== 'approved') {
                throw new Exception('Only approved budgets can be activated');
            }
            
            $stmt = $conn->prepare("
                UPDATE budgets 
                SET status = 'active'
                WHERE budget_id = ? AND company_id = ?
            ");
            
            $stmt->execute([$_POST['budget_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Budget activated successfully']);
            
        } elseif ($_POST['action'] === 'close_budget') {
            if (empty($_POST['budget_id'])) {
                throw new Exception('Budget ID is required');
            }
            
            $stmt = $conn->prepare("
                UPDATE budgets 
                SET status = 'closed'
                WHERE budget_id = ? AND company_id = ?
            ");
            
            $stmt->execute([$_POST['budget_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Budget closed successfully']);
            
        } elseif ($_POST['action'] === 'add_budget_line') {
            if (empty($_POST['budget_id']) || empty($_POST['account_id']) || empty($_POST['budgeted_amount'])) {
                throw new Exception('Budget ID, account, and budgeted amount are required');
            }
            
            // Check if line already exists for this account
            $check = $conn->prepare("SELECT budget_line_id FROM budget_lines WHERE budget_id = ? AND account_id = ?");
            $check->execute([$_POST['budget_id'], $_POST['account_id']]);
            
            if ($check->rowCount() > 0) {
                throw new Exception('Budget line already exists for this account');
            }
            
            $stmt = $conn->prepare("
                INSERT INTO budget_lines (budget_id, account_id, budgeted_amount, actual_amount, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ");
            
            $stmt->execute([
                $_POST['budget_id'],
                $_POST['account_id'],
                $_POST['budgeted_amount']
            ]);
            
            // Update budget total
            $update_total = $conn->prepare("
                UPDATE budgets 
                SET total_amount = (SELECT SUM(budgeted_amount) FROM budget_lines WHERE budget_id = ?)
                WHERE budget_id = ?
            ");
            $update_total->execute([$_POST['budget_id'], $_POST['budget_id']]);
            
            echo json_encode(['success' => true, 'message' => 'Budget line added successfully']);
            
        } elseif ($_POST['action'] === 'update_budget_line') {
            if (empty($_POST['budget_line_id']) || empty($_POST['budgeted_amount'])) {
                throw new Exception('Budget line ID and budgeted amount are required');
            }
            
            // Get budget_id
            $get_budget = $conn->prepare("SELECT budget_id FROM budget_lines WHERE budget_line_id = ?");
            $get_budget->execute([$_POST['budget_line_id']]);
            $line = $get_budget->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $conn->prepare("
                UPDATE budget_lines 
                SET budgeted_amount = ?,
                    updated_at = NOW()
                WHERE budget_line_id = ?
            ");
            
            $stmt->execute([
                $_POST['budgeted_amount'],
                $_POST['budget_line_id']
            ]);
            
            // Update budget total
            $update_total = $conn->prepare("
                UPDATE budgets 
                SET total_amount = (SELECT SUM(budgeted_amount) FROM budget_lines WHERE budget_id = ?)
                WHERE budget_id = ?
            ");
            $update_total->execute([$line['budget_id'], $line['budget_id']]);
            
            echo json_encode(['success' => true, 'message' => 'Budget line updated successfully']);
            
        } elseif ($_POST['action'] === 'delete_budget_line') {
            if (empty($_POST['budget_line_id'])) {
                throw new Exception('Budget line ID is required');
            }
            
            // Get budget_id before deletion
            $get_budget = $conn->prepare("SELECT budget_id FROM budget_lines WHERE budget_line_id = ?");
            $get_budget->execute([$_POST['budget_line_id']]);
            $line = $get_budget->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $conn->prepare("DELETE FROM budget_lines WHERE budget_line_id = ?");
            $stmt->execute([$_POST['budget_line_id']]);
            
            // Update budget total
            if ($line) {
                $update_total = $conn->prepare("
                    UPDATE budgets 
                    SET total_amount = (SELECT COALESCE(SUM(budgeted_amount), 0) FROM budget_lines WHERE budget_id = ?)
                    WHERE budget_id = ?
                ");
                $update_total->execute([$line['budget_id'], $line['budget_id']]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Budget line deleted successfully']);
            
        } elseif ($_POST['action'] === 'get_budget_lines') {
            if (empty($_POST['budget_id'])) {
                throw new Exception('Budget ID is required');
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    bl.*,
                    ca.account_code,
                    ca.account_name,
                    ca.account_type
                FROM budget_lines bl
                INNER JOIN chart_of_accounts ca ON bl.account_id = ca.account_id
                WHERE bl.budget_id = ?
                ORDER BY ca.account_code
            ");
            $stmt->execute([$_POST['budget_id']]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'lines' => $lines]);
            
        } elseif ($_POST['action'] === 'get_budget_by_module') {
            if (empty($_POST['budget_id']) || empty($_POST['module'])) {
                throw new Exception('Budget ID and module are required');
            }
            
            // Get accounts for specific module based on account type
            $account_filter = '';
            switch ($_POST['module']) {
                case 'marketing':
                    $account_filter = "AND (ca.account_code LIKE '6200%' OR ca.account_name LIKE '%marketing%' OR ca.account_name LIKE '%advertising%')";
                    break;
                case 'sales':
                    $account_filter = "AND (ca.account_code LIKE '6200%' OR ca.account_code LIKE '5130%' OR ca.account_name LIKE '%sales%' OR ca.account_name LIKE '%commission%')";
                    break;
                case 'expenses':
                    $account_filter = "AND ca.account_type = 'expense'";
                    break;
                case 'procurement':
                    $account_filter = "AND (ca.account_code LIKE '5100%' OR ca.account_name LIKE '%procurement%' OR ca.account_name LIKE '%purchase%')";
                    break;
                case 'hr':
                    $account_filter = "AND (ca.account_code LIKE '6110%' OR ca.account_name LIKE '%salary%' OR ca.account_name LIKE '%wages%' OR ca.account_name LIKE '%payroll%')";
                    break;
                case 'operations':
                    $account_filter = "AND ca.account_code LIKE '6%'";
                    break;
                default:
                    $account_filter = "";
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    bl.*,
                    ca.account_code,
                    ca.account_name,
                    ca.account_type
                FROM budget_lines bl
                INNER JOIN chart_of_accounts ca ON bl.account_id = ca.account_id
                WHERE bl.budget_id = ? $account_filter
                ORDER BY ca.account_code
            ");
            $stmt->execute([$_POST['budget_id']]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'lines' => $lines]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch budgets
try {
    $stmt = $conn->prepare("
        SELECT 
            b.*,
            u1.first_name as creator_first_name,
            u1.last_name as creator_last_name,
            u2.first_name as approver_first_name,
            u2.last_name as approver_last_name,
            (SELECT COUNT(*) FROM budget_lines WHERE budget_id = b.budget_id) as line_count,
            (SELECT SUM(actual_amount) FROM budget_lines WHERE budget_id = b.budget_id) as total_actual
        FROM budgets b
        LEFT JOIN users u1 ON b.created_by = u1.user_id
        LEFT JOIN users u2 ON b.approved_by = u2.user_id
        WHERE b.company_id = ?
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$company_id]);
    $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats = $conn->prepare("
        SELECT 
            COUNT(*) as total_budgets,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_budgets,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_budgets,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_budgets,
            COALESCE(SUM(total_amount), 0) as total_budgeted
        FROM budgets 
        WHERE company_id = ?
    ");
    $stats->execute([$company_id]);
    $statistics = $stats->fetch(PDO::FETCH_ASSOC);
    
    // Get accounts for dropdown
    $accounts_stmt = $conn->prepare("
        SELECT account_id, account_code, account_name, account_type
        FROM chart_of_accounts
        WHERE company_id = ? AND is_active = 1
        ORDER BY account_code
    ");
    $accounts_stmt->execute([$company_id]);
    $accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group accounts by type for better organization
    $grouped_accounts = [
        'expense' => [],
        'revenue' => [],
        'asset' => [],
        'liability' => [],
        'equity' => []
    ];
    
    foreach ($accounts as $account) {
        $grouped_accounts[$account['account_type']][] = $account;
    }
    
} catch (PDOException $e) {
    $error_message = "Error fetching budgets: " . $e->getMessage();
    $budgets = [];
    $statistics = ['total_budgets' => 0, 'draft_budgets' => 0, 'approved_budgets' => 0, 'active_budgets' => 0, 'total_budgeted' => 0];
    $accounts = [];
    $grouped_accounts = [];
}

$page_title = 'Budget Management';
require_once '../../includes/header.php';
?>

<style>
.budget-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
}

.budget-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.card-header {
    background: #fff;
    border-bottom: 2px solid #f3f4f6;
    border-radius: 12px 12px 0 0 !important;
    padding: 1.25rem 1.5rem;
}

.form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.625rem 0.875rem;
}

.btn {
    border-radius: 8px;
    padding: 0.625rem 1.25rem;
    font-weight: 500;
}

.progress {
    height: 8px;
    border-radius: 4px;
}

.budget-line-row:hover {
    background: #f9fafb;
}

.variance-positive {
    color: #059669;
    font-weight: 600;
}

.variance-negative {
    color: #dc2626;
    font-weight: 600;
}

.modal-lg {
    max-width: 900px;
}

.module-tab {
    cursor: pointer;
    padding: 0.75rem 1.5rem;
    border-radius: 8px 8px 0 0;
    background: #f3f4f6;
    margin-right: 0.25rem;
    transition: all 0.2s;
}

.module-tab:hover {
    background: #e5e7eb;
}

.module-tab.active {
    background: #fff;
    border-bottom: 3px solid #667eea;
}

.module-summary {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-chart-pie text-primary me-2"></i>Budget Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Create and manage budgets for all departments</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-primary" onclick="showBudgetModal()">
                        <i class="fas fa-plus me-2"></i>Create Budget
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Total Budgets</h6>
                                <h2 class="fw-bold mb-0"><?php echo (int)$statistics['total_budgets']; ?></h2>
                            </div>
                            <div class="fs-1 text-primary">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Active Budgets</h6>
                                <h2 class="fw-bold mb-0 text-success"><?php echo (int)$statistics['active_budgets']; ?></h2>
                            </div>
                            <div class="fs-1 text-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Pending Approval</h6>
                                <h2 class="fw-bold mb-0 text-warning"><?php echo (int)$statistics['draft_budgets']; ?></h2>
                            </div>
                            <div class="fs-1 text-warning">
                                <i class="fas fa-clock"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Total Budgeted</h6>
                                <h2 class="fw-bold mb-0">TZS <?php echo number_format((float)$statistics['total_budgeted'], 0); ?></h2>
                            </div>
                            <div class="fs-1 text-info">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Budgets List -->
        <?php if (count($budgets) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>All Budgets
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="budgetsTable">
                        <thead>
                            <tr>
                                <th>Budget Name</th>
                                <th>Period</th>
                                <th>Fiscal Year</th>
                                <th>Duration</th>
                                <th>Total Amount</th>
                                <th>Actual Spent</th>
                                <th>Variance</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($budgets as $budget): ?>
                            <?php 
                                $total_amount = (float)($budget['total_amount'] ?? 0);
                                $total_actual = (float)($budget['total_actual'] ?? 0);
                                $variance = $total_amount - $total_actual;
                                $variance_percent = $total_amount > 0 ? ($variance / $total_amount) * 100 : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($budget['budget_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo ucfirst($budget['budget_period']); ?></span>
                                </td>
                                <td><?php echo $budget['fiscal_year']; ?></td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($budget['start_date'])); ?><br>
                                    <small class="text-muted">to <?php echo date('M d, Y', strtotime($budget['end_date'])); ?></small>
                                </td>
                                <td><strong>TZS <?php echo number_format($total_amount, 2); ?></strong></td>
                                <td>TZS <?php echo number_format($total_actual, 2); ?></td>
                                <td>
                                    <span class="<?php echo $variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                        TZS <?php echo number_format($variance, 2); ?>
                                        <small>(<?php echo number_format($variance_percent, 1); ?>%)</small>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php 
                                        echo match($budget['status']) {
                                            'draft' => 'bg-secondary text-white',
                                            'approved' => 'bg-info text-white',
                                            'active' => 'bg-success text-white',
                                            'closed' => 'bg-dark text-white',
                                            default => 'bg-secondary text-white'
                                        };
                                    ?>">
                                        <?php echo ucfirst($budget['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($budget['creator_first_name'] . ' ' . $budget['creator_last_name']); ?>
                                    <br><small class="text-muted"><?php echo date('M d, Y', strtotime($budget['created_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="viewBudgetLines(<?php echo $budget['budget_id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($budget['status'] === 'draft'): ?>
                                        <button class="btn btn-outline-success" onclick="editBudget(<?php echo $budget['budget_id']; ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-info" onclick="approveBudget(<?php echo $budget['budget_id']; ?>)" title="Approve">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($budget['status'] === 'approved'): ?>
                                        <button class="btn btn-outline-success" onclick="activateBudget(<?php echo $budget['budget_id']; ?>)" title="Activate">
                                            <i class="fas fa-play"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($budget['status'] === 'active'): ?>
                                        <button class="btn btn-outline-warning" onclick="closeBudget(<?php echo $budget['budget_id']; ?>)" title="Close">
                                            <i class="fas fa-lock"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($budget['status'] === 'draft' && $budget['line_count'] == 0): ?>
                                        <button class="btn btn-outline-danger" onclick="deleteBudget(<?php echo $budget['budget_id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-chart-pie fa-4x text-muted mb-4"></i>
                <h4 class="text-muted">No Budgets Found</h4>
                <p class="text-muted">Create your first budget to start managing your finances</p>
                <button class="btn btn-primary mt-3" onclick="showBudgetModal()">
                    <i class="fas fa-plus me-2"></i>Create Your First Budget
                </button>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
</section>

<!-- Budget Modal -->
<div class="modal fade" id="budgetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-chart-pie me-2"></i>
                    <span id="modalTitle">Create Budget</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="budgetForm">
                <div class="modal-body">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" value="create_budget">
                    <input type="hidden" name="budget_id" id="budget_id">
                    
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Budget Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="budget_name" id="budget_name" required 
                                   placeholder="e.g., Annual Budget 2025">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Budget Period <span class="text-danger">*</span></label>
                            <select class="form-select" name="budget_period" id="budget_period" required>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annual" selected>Annual</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Fiscal Year <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="fiscal_year" id="fiscal_year" 
                                   value="<?php echo date('Y'); ?>" min="2020" max="2100" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" id="start_date" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="end_date" id="end_date" required>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Total Amount</label>
                            <input type="number" class="form-control" name="total_amount" id="total_amount" 
                                   step="0.01" min="0" value="0" readonly>
                            <small class="text-muted">This will be calculated from budget lines</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Budget
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Budget Lines Modal -->
<div class="modal fade" id="budgetLinesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-list me-2"></i>
                    <span id="linesModalTitle">Budget Lines</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="current_budget_id">
                
                <!-- Module Tabs -->
                <div class="d-flex mb-3" style="overflow-x: auto;">
                    <div class="module-tab active" data-module="all" onclick="filterByModule('all')">
                        <i class="fas fa-globe me-1"></i>All
                    </div>
                    <div class="module-tab" data-module="marketing" onclick="filterByModule('marketing')">
                        <i class="fas fa-bullhorn me-1"></i>Marketing
                    </div>
                    <div class="module-tab" data-module="sales" onclick="filterByModule('sales')">
                        <i class="fas fa-handshake me-1"></i>Sales
                    </div>
                    <div class="module-tab" data-module="expenses" onclick="filterByModule('expenses')">
                        <i class="fas fa-receipt me-1"></i>Expenses
                    </div>
                    <div class="module-tab" data-module="procurement" onclick="filterByModule('procurement')">
                        <i class="fas fa-shopping-cart me-1"></i>Procurement
                    </div>
                    <div class="module-tab" data-module="hr" onclick="filterByModule('hr')">
                        <i class="fas fa-users me-1"></i>HR & Payroll
                    </div>
                    <div class="module-tab" data-module="operations" onclick="filterByModule('operations')">
                        <i class="fas fa-cogs me-1"></i>Operations
                    </div>
                </div>
                
                <!-- Add Line Form -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Add Budget Line</h6>
                        <form id="budgetLineForm">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label">Account</label>
                                    <select class="form-select" id="line_account_id" required>
                                        <option value="">Select Account</option>
                                        <?php foreach ($grouped_accounts as $type => $accts): ?>
                                            <?php if (count($accts) > 0): ?>
                                            <optgroup label="<?php echo strtoupper($type); ?>">
                                                <?php foreach ($accts as $account): ?>
                                                <option value="<?php echo $account['account_id']; ?>">
                                                    <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Budgeted Amount</label>
                                    <input type="number" class="form-control" id="line_budgeted_amount" 
                                           step="0.01" min="0" required>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="fas fa-plus me-1"></i>Add Line
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Budget Lines Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th class="text-end">Budgeted</th>
                                <th class="text-end">Actual</th>
                                <th class="text-end">Variance</th>
                                <th class="text-center">% Used</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="budgetLinesTableBody">
                            <tr>
                                <td colspan="6" class="text-center text-muted">Loading...</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold">
                                <td>TOTAL</td>
                                <td class="text-end" id="total_budgeted">0.00</td>
                                <td class="text-end" id="total_actual">0.00</td>
                                <td class="text-end" id="total_variance">0.00</td>
                                <td class="text-center" id="total_percentage">0%</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
let budgetModal, budgetLinesModal;
let currentModule = 'all';
let allBudgetLines = [];

$(document).ready(function() {
    budgetModal = new bootstrap.Modal(document.getElementById('budgetModal'));
    budgetLinesModal = new bootstrap.Modal(document.getElementById('budgetLinesModal'));
    
    // Initialize DataTable
    $('#budgetsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25
    });
});

function showBudgetModal() {
    document.getElementById('budgetForm').reset();
    document.getElementById('budget_id').value = '';
    document.getElementById('modalTitle').textContent = 'Create Budget';
    document.querySelector('#budgetForm input[name="action"]').value = 'create_budget';
    budgetModal.show();
}

function editBudget(budgetId) {
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_budget',
            budget_id: budgetId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const budget = response.budget;
                document.getElementById('budget_id').value = budget.budget_id;
                document.getElementById('budget_name').value = budget.budget_name;
                document.getElementById('budget_period').value = budget.budget_period;
                document.getElementById('fiscal_year').value = budget.fiscal_year;
                document.getElementById('start_date').value = budget.start_date;
                document.getElementById('end_date').value = budget.end_date;
                document.getElementById('total_amount').value = budget.total_amount;
                
                document.getElementById('modalTitle').textContent = 'Edit Budget';
                document.querySelector('#budgetForm input[name="action"]').value = 'update_budget';
                budgetModal.show();
            } else {
                alert('Error: ' + response.message);
            }
        }
    });
}

function deleteBudget(budgetId) {
    if (confirm('Are you sure you want to delete this budget?')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_budget',
                budget_id: budgetId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    }
}

function approveBudget(budgetId) {
    if (confirm('Are you sure you want to approve this budget?')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'approve_budget',
                budget_id: budgetId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    }
}

function activateBudget(budgetId) {
    if (confirm('Are you sure you want to activate this budget?')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'activate_budget',
                budget_id: budgetId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    }
}

function closeBudget(budgetId) {
    if (confirm('Are you sure you want to close this budget? This action cannot be undone.')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'close_budget',
                budget_id: budgetId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    }
}

function viewBudgetLines(budgetId) {
    document.getElementById('current_budget_id').value = budgetId;
    currentModule = 'all';
    document.querySelectorAll('.module-tab').forEach(tab => tab.classList.remove('active'));
    document.querySelector('[data-module="all"]').classList.add('active');
    loadBudgetLines(budgetId);
    budgetLinesModal.show();
}

function filterByModule(module) {
    currentModule = module;
    document.querySelectorAll('.module-tab').forEach(tab => tab.classList.remove('active'));
    document.querySelector(`[data-module="${module}"]`).classList.add('active');
    
    const budgetId = document.getElementById('current_budget_id').value;
    loadBudgetLines(budgetId, module);
}

function loadBudgetLines(budgetId, module = 'all') {
    const action = module === 'all' ? 'get_budget_lines' : 'get_budget_by_module';
    const data = {
        ajax: 1,
        action: action,
        budget_id: budgetId
    };
    
    if (module !== 'all') {
        data.module = module;
    }
    
    $.ajax({
        url: '',
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                allBudgetLines = response.lines;
                renderBudgetLines(response.lines);
            }
        }
    });
}

function renderBudgetLines(lines) {
    const tbody = document.getElementById('budgetLinesTableBody');
    tbody.innerHTML = '';
    
    let totalBudgeted = 0;
    let totalActual = 0;
    
    if (lines.length > 0) {
        lines.forEach(function(line) {
            const budgeted = parseFloat(line.budgeted_amount) || 0;
            const actual = parseFloat(line.actual_amount) || 0;
            totalBudgeted += budgeted;
            totalActual += actual;
            const variance = budgeted - actual;
            const percentUsed = budgeted > 0 ? (actual / budgeted * 100) : 0;
            
            const row = `
                <tr class="budget-line-row">
                    <td>
                        <strong>${line.account_code}</strong><br>
                        <small class="text-muted">${line.account_name}</small>
                    </td>
                    <td class="text-end">TZS ${budgeted.toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-end">TZS ${actual.toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-end">
                        <span class="${variance >= 0 ? 'variance-positive' : 'variance-negative'}">
                            TZS ${variance.toLocaleString('en-US', {minimumFractionDigits: 2})}
                        </span>
                    </td>
                    <td class="text-center">
                        <div class="progress" style="height: 20px;">
                            <div class="progress-bar ${percentUsed > 100 ? 'bg-danger' : percentUsed > 80 ? 'bg-warning' : 'bg-success'}" 
                                 style="width: ${Math.min(percentUsed, 100)}%">
                                ${percentUsed.toFixed(1)}%
                            </div>
                        </div>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteBudgetLine(${line.budget_line_id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    } else {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No budget lines found for this module</td></tr>';
    }
    
    const totalVariance = totalBudgeted - totalActual;
    const totalPercent = totalBudgeted > 0 ? (totalActual / totalBudgeted * 100) : 0;
    
    document.getElementById('total_budgeted').textContent = 'TZS ' + totalBudgeted.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('total_actual').textContent = 'TZS ' + totalActual.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('total_variance').innerHTML = `<span class="${totalVariance >= 0 ? 'variance-positive' : 'variance-negative'}">TZS ${totalVariance.toLocaleString('en-US', {minimumFractionDigits: 2})}</span>`;
    document.getElementById('total_percentage').textContent = totalPercent.toFixed(1) + '%';
}

function deleteBudgetLine(lineId) {
    if (confirm('Are you sure you want to delete this budget line?')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_budget_line',
                budget_line_id: lineId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const budgetId = document.getElementById('current_budget_id').value;
                    loadBudgetLines(budgetId, currentModule);
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    }
}

// Save budget
document.getElementById('budgetForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        }
    });
});

// Add budget line
document.getElementById('budgetLineForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const budgetId = document.getElementById('current_budget_id').value;
    const accountId = document.getElementById('line_account_id').value;
    const budgetedAmount = document.getElementById('line_budgeted_amount').value;
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'add_budget_line',
            budget_id: budgetId,
            account_id: accountId,
            budgeted_amount: budgetedAmount
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                document.getElementById('budgetLineForm').reset();
                loadBudgetLines(budgetId, currentModule);
            } else {
                alert('Error: ' + response.message);
            }
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>