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

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        if (empty($_POST['budget_name'])) {
            $errors[] = "Budget name is required";
        }
        if (empty($_POST['start_date'])) {
            $errors[] = "Start date is required";
        }
        if (empty($_POST['end_date'])) {
            $errors[] = "End date is required";
        }
        
        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                
                if ($action === 'create') {
                    $sql = "INSERT INTO budgets (
                        company_id, budget_name, budget_year, budget_period,
                        fiscal_year, start_date, end_date, total_amount,
                        status, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $company_id,
                        $_POST['budget_name'],
                        intval($_POST['budget_year'] ?? date('Y')),
                        $_POST['budget_period'] ?? 'annual',
                        intval($_POST['fiscal_year'] ?? date('Y')),
                        $_POST['start_date'],
                        $_POST['end_date'],
                        floatval($_POST['total_amount'] ?? 0),
                        'draft',
                        $_SESSION['user_id']
                    ]);
                    
                    $budget_id = $conn->lastInsertId();
                    $success = "Budget created successfully!";
                } else {
                    $budget_id = $_POST['budget_id'];
                    
                    $sql = "UPDATE budgets SET 
                        budget_name = ?, budget_year = ?, budget_period = ?,
                        fiscal_year = ?, start_date = ?, end_date = ?,
                        total_amount = ?, updated_by = ?, updated_at = NOW()
                        WHERE budget_id = ? AND company_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $_POST['budget_name'],
                        intval($_POST['budget_year'] ?? date('Y')),
                        $_POST['budget_period'] ?? 'annual',
                        intval($_POST['fiscal_year'] ?? date('Y')),
                        $_POST['start_date'],
                        $_POST['end_date'],
                        floatval($_POST['total_amount'] ?? 0),
                        $_SESSION['user_id'],
                        $budget_id,
                        $company_id
                    ]);
                    
                    $success = "Budget updated successfully!";
                }
                
                $conn->commit();
                header("Location: budgets.php?id=" . $budget_id);
                exit;
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Error saving budget: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        try {
            $stmt = $conn->prepare("DELETE FROM budgets WHERE budget_id = ? AND company_id = ?");
            $stmt->execute([$_POST['budget_id'], $company_id]);
            $success = "Budget deleted successfully!";
        } catch (PDOException $e) {
            error_log("Error deleting budget: " . $e->getMessage());
            $errors[] = "Error deleting budget";
        }
    } elseif ($action === 'approve') {
        try {
            $stmt = $conn->prepare("
                UPDATE budgets 
                SET status = 'approved', 
                    approved_by = ?, 
                    approved_at = NOW()
                WHERE budget_id = ? AND company_id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $_POST['budget_id'], $company_id]);
            $success = "Budget approved successfully!";
        } catch (PDOException $e) {
            error_log("Error approving budget: " . $e->getMessage());
            $errors[] = "Error approving budget";
        }
    }
}

// Fetch budgets with statistics
try {
    $stmt = $conn->prepare("
        SELECT b.*,
               u.full_name as created_by_name,
               au.full_name as approved_by_name,
               (SELECT COUNT(*) FROM budget_lines bl WHERE bl.budget_id = b.budget_id) as line_count,
               (SELECT COALESCE(SUM(budgeted_amount), 0) FROM budget_lines bl WHERE bl.budget_id = b.budget_id) as total_budgeted,
               (SELECT COALESCE(SUM(actual_amount), 0) FROM budget_lines bl WHERE bl.budget_id = b.budget_id) as total_actual
        FROM budgets b
        LEFT JOIN users u ON b.created_by = u.user_id
        LEFT JOIN users au ON b.approved_by = au.user_id
        WHERE b.company_id = ?
        ORDER BY b.fiscal_year DESC, b.created_at DESC
    ");
    $stmt->execute([$company_id]);
    $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching budgets: " . $e->getMessage());
    $budgets = [];
}

// Calculate statistics
$total_budgets = count($budgets);
$active_budgets = 0;
$total_budgeted = 0;
$total_spent = 0;

foreach ($budgets as $budget) {
    if ($budget['status'] === 'active') {
        $active_budgets++;
    }
    $total_budgeted += $budget['total_budgeted'];
    $total_spent += $budget['total_actual'];
}

$page_title = 'Budget Management';
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

.budget-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
    border-left: 4px solid #007bff;
}

.budget-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    transform: translateX(4px);
}

.budget-card.draft { border-left-color: #6c757d; }
.budget-card.approved { border-left-color: #17a2b8; }
.budget-card.active { border-left-color: #28a745; }
.budget-card.closed { border-left-color: #dc3545; }

.budget-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.budget-name {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2c3e50;
}

.budget-period {
    font-size: 0.875rem;
    color: #6c757d;
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

.status-badge.approved {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.closed {
    background: #f8d7da;
    color: #721c24;
}

.budget-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e9ecef;
}

.metric-item {
    text-align: center;
}

.metric-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2c3e50;
}

.metric-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
}

.variance-bar {
    height: 8px;
    background: #e9ecef;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.variance-fill {
    height: 100%;
    transition: width 0.3s;
}

.variance-fill.under {
    background: linear-gradient(90deg, #28a745, #20c997);
}

.variance-fill.over {
    background: linear-gradient(90deg, #ffc107, #fd7e14);
}

.variance-fill.exceeded {
    background: linear-gradient(90deg, #dc3545, #c82333);
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-chart-line text-primary me-2"></i>Budget Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Plan and track organizational budgets</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#budgetModal">
                        <i class="fas fa-plus-circle me-1"></i> Create Budget
                    </button>
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
                    <div class="stats-number"><?php echo number_format($total_budgets); ?></div>
                    <div class="stats-label">Total Budgets</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($active_budgets); ?></div>
                    <div class="stats-label">Active Budgets</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">TSH <?php echo number_format($total_budgeted / 1000000, 2); ?>M</div>
                    <div class="stats-label">Total Budgeted</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format($total_spent / 1000000, 2); ?>M</div>
                    <div class="stats-label">Total Spent</div>
                </div>
            </div>
        </div>

        <!-- Budgets List -->
        <?php if (empty($budgets)): ?>
        <div class="text-center py-5">
            <i class="fas fa-chart-line fa-4x text-muted mb-3"></i>
            <h4>No Budgets Found</h4>
            <p class="text-muted">Start by creating your first budget</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#budgetModal">
                <i class="fas fa-plus-circle me-1"></i> Create Budget
            </button>
        </div>
        <?php else: ?>
            <?php foreach ($budgets as $budget): ?>
            <?php
            $utilization = $budget['total_budgeted'] > 0 ? ($budget['total_actual'] / $budget['total_budgeted']) * 100 : 0;
            $variance = $budget['total_budgeted'] - $budget['total_actual'];
            $variance_class = $utilization <= 100 ? 'under' : ($utilization <= 110 ? 'over' : 'exceeded');
            ?>
            <div class="budget-card <?php echo $budget['status']; ?>">
                <div class="budget-header">
                    <div class="flex-grow-1">
                        <div class="budget-name">
                            <i class="fas fa-calculator me-2 text-primary"></i>
                            <?php echo htmlspecialchars($budget['budget_name']); ?>
                        </div>
                        <div class="budget-period">
                            <i class="fas fa-calendar me-1"></i>
                            FY <?php echo $budget['fiscal_year']; ?> 
                            (<?php echo date('M d, Y', strtotime($budget['start_date'])); ?> - 
                            <?php echo date('M d, Y', strtotime($budget['end_date'])); ?>)
                        </div>
                    </div>
                    <div>
                        <span class="status-badge <?php echo $budget['status']; ?>">
                            <?php echo ucfirst($budget['status']); ?>
                        </span>
                    </div>
                </div>

                <div class="budget-metrics">
                    <div class="metric-item">
                        <div class="metric-value">TSH <?php echo number_format($budget['total_budgeted']); ?></div>
                        <div class="metric-label">Budgeted</div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-value">TSH <?php echo number_format($budget['total_actual']); ?></div>
                        <div class="metric-label">Actual</div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-value <?php echo $variance >= 0 ? 'text-success' : 'text-danger'; ?>">
                            TSH <?php echo number_format(abs($variance)); ?>
                        </div>
                        <div class="metric-label">Variance</div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-value"><?php echo number_format($utilization, 1); ?>%</div>
                        <div class="metric-label">Utilization</div>
                    </div>
                </div>

                <div class="variance-bar">
                    <div class="variance-fill <?php echo $variance_class; ?>" 
                         style="width: <?php echo min($utilization, 100); ?>%"></div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <a href="budget_details.php?id=<?php echo $budget['budget_id']; ?>" 
                       class="btn btn-sm btn-primary">
                        <i class="fas fa-eye me-1"></i> View Details
                    </a>
                    <?php if ($budget['status'] === 'draft'): ?>
                    <button type="button" 
                            class="btn btn-sm btn-success"
                            onclick="approveBudget(<?php echo $budget['budget_id']; ?>)">
                        <i class="fas fa-check me-1"></i> Approve
                    </button>
                    <button type="button" 
                            class="btn btn-sm btn-warning"
                            onclick="editBudget(<?php echo htmlspecialchars(json_encode($budget)); ?>)">
                        <i class="fas fa-edit me-1"></i> Edit
                    </button>
                    <?php endif; ?>
                    <button type="button" 
                            class="btn btn-sm btn-outline-danger"
                            onclick="deleteBudget(<?php echo $budget['budget_id']; ?>, '<?php echo htmlspecialchars($budget['budget_name']); ?>')">
                        <i class="fas fa-trash me-1"></i> Delete
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</section>

<!-- Create/Edit Budget Modal -->
<div class="modal fade" id="budgetModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-chart-line me-2"></i>Create Budget
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="budgetForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="budget_id" id="budget_id">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Budget Name <span class="text-danger">*</span></label>
                            <input type="text" name="budget_name" id="budget_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Budget Period</label>
                            <select name="budget_period" id="budget_period" class="form-select">
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annual" selected>Annual</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fiscal Year</label>
                            <input type="number" name="fiscal_year" id="fiscal_year" class="form-control" 
                                   value="<?php echo date('Y'); ?>" min="2020" max="2050">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Budget Year</label>
                            <input type="number" name="budget_year" id="budget_year" class="form-control" 
                                   value="<?php echo date('Y'); ?>" min="2020" max="2050">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="end_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Total Amount</label>
                            <input type="number" name="total_amount" id="total_amount" class="form-control" 
                                   step="0.01" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Budget
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editBudget(budget) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Budget';
    document.getElementById('formAction').value = 'update';
    document.getElementById('budget_id').value = budget.budget_id;
    document.getElementById('budget_name').value = budget.budget_name;
    document.getElementById('budget_period').value = budget.budget_period;
    document.getElementById('fiscal_year').value = budget.fiscal_year;
    document.getElementById('budget_year').value = budget.budget_year;
    document.getElementById('start_date').value = budget.start_date;
    document.getElementById('end_date').value = budget.end_date;
    document.getElementById('total_amount').value = budget.total_amount;
    
    const modal = new bootstrap.Modal(document.getElementById('budgetModal'));
    modal.show();
}

function deleteBudget(budgetId, budgetName) {
    if (confirm(`Are you sure you want to delete "${budgetName}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="budget_id" value="${budgetId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function approveBudget(budgetId) {
    if (confirm('Are you sure you want to approve this budget?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="budget_id" value="${budgetId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset form when modal is closed
document.getElementById('budgetModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('budgetForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-chart-line me-2"></i>Create Budget';
    document.getElementById('formAction').value = 'create';
    document.getElementById('budget_id').value = '';
});
</script>

<?php 
require_once '../../includes/footer.php';
?>