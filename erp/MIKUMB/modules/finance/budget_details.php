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

// Get budget ID
$budget_id = $_GET['id'] ?? null;

if (!$budget_id) {
    header("Location: budgets.php");
    exit;
}

// Fetch budget details
try {
    $stmt = $conn->prepare("
        SELECT b.*,
               u.full_name as created_by_name,
               au.full_name as approved_by_name
        FROM budgets b
        LEFT JOIN users u ON b.created_by = u.user_id
        LEFT JOIN users au ON b.approved_by = au.user_id
        WHERE b.budget_id = ? AND b.company_id = ?
    ");
    $stmt->execute([$budget_id, $company_id]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$budget) {
        header("Location: budgets.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching budget: " . $e->getMessage());
    header("Location: budgets.php");
    exit;
}

// Fetch chart of accounts for budget lines
try {
    $stmt = $conn->prepare("
        SELECT account_id, account_code, account_name, account_type
        FROM chart_of_accounts
        WHERE company_id = ? AND is_active = 1
        ORDER BY account_code
    ");
    $stmt->execute([$company_id]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching accounts: " . $e->getMessage());
    $accounts = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_line' || $action === 'update_line') {
        if (empty($_POST['account_id'])) {
            $errors[] = "Account is required";
        }
        if (empty($_POST['budgeted_amount']) || floatval($_POST['budgeted_amount']) <= 0) {
            $errors[] = "Valid budgeted amount is required";
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'add_line') {
                    $sql = "INSERT INTO budget_lines (
                        budget_id, account_id, budgeted_amount, actual_amount
                    ) VALUES (?, ?, ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $budget_id,
                        $_POST['account_id'],
                        floatval($_POST['budgeted_amount']),
                        floatval($_POST['actual_amount'] ?? 0)
                    ]);
                    
                    $success = "Budget line added successfully!";
                } else {
                    $sql = "UPDATE budget_lines SET 
                        account_id = ?, budgeted_amount = ?, actual_amount = ?,
                        updated_at = NOW()
                        WHERE budget_line_id = ? AND budget_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $_POST['account_id'],
                        floatval($_POST['budgeted_amount']),
                        floatval($_POST['actual_amount'] ?? 0),
                        $_POST['budget_line_id'],
                        $budget_id
                    ]);
                    
                    $success = "Budget line updated successfully!";
                }
                
                // Update budget total
                $stmt = $conn->prepare("
                    UPDATE budgets 
                    SET total_amount = (SELECT COALESCE(SUM(budgeted_amount), 0) FROM budget_lines WHERE budget_id = ?)
                    WHERE budget_id = ?
                ");
                $stmt->execute([$budget_id, $budget_id]);
                
            } catch (PDOException $e) {
                error_log("Error saving budget line: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_line') {
        try {
            $stmt = $conn->prepare("DELETE FROM budget_lines WHERE budget_line_id = ? AND budget_id = ?");
            $stmt->execute([$_POST['budget_line_id'], $budget_id]);
            
            // Update budget total
            $stmt = $conn->prepare("
                UPDATE budgets 
                SET total_amount = (SELECT COALESCE(SUM(budgeted_amount), 0) FROM budget_lines WHERE budget_id = ?)
                WHERE budget_id = ?
            ");
            $stmt->execute([$budget_id, $budget_id]);
            
            $success = "Budget line deleted successfully!";
        } catch (PDOException $e) {
            error_log("Error deleting budget line: " . $e->getMessage());
            $errors[] = "Error deleting budget line";
        }
    }
}

// Fetch budget lines
try {
    $stmt = $conn->prepare("
        SELECT bl.*,
               a.account_code, a.account_name, a.account_type
        FROM budget_lines bl
        INNER JOIN chart_of_accounts a ON bl.account_id = a.account_id
        WHERE bl.budget_id = ?
        ORDER BY a.account_code
    ");
    $stmt->execute([$budget_id]);
    $budget_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching budget lines: " . $e->getMessage());
    $budget_lines = [];
}

// Calculate totals and statistics
$total_budgeted = 0;
$total_actual = 0;
$total_variance = 0;
$lines_over_budget = 0;
$lines_under_budget = 0;

foreach ($budget_lines as $line) {
    $total_budgeted += $line['budgeted_amount'];
    $total_actual += $line['actual_amount'];
    $variance = $line['budgeted_amount'] - $line['actual_amount'];
    $total_variance += $variance;
    
    if ($variance < 0) {
        $lines_over_budget++;
    } elseif ($variance > 0) {
        $lines_under_budget++;
    }
}

$utilization_percentage = $total_budgeted > 0 ? ($total_actual / $total_budgeted) * 100 : 0;

// Group by account type for summary
$summary_by_type = [];
foreach ($budget_lines as $line) {
    $type = $line['account_type'];
    if (!isset($summary_by_type[$type])) {
        $summary_by_type[$type] = [
            'budgeted' => 0,
            'actual' => 0,
            'variance' => 0,
            'count' => 0
        ];
    }
    $summary_by_type[$type]['budgeted'] += $line['budgeted_amount'];
    $summary_by_type[$type]['actual'] += $line['actual_amount'];
    $summary_by_type[$type]['variance'] += ($line['budgeted_amount'] - $line['actual_amount']);
    $summary_by_type[$type]['count']++;
}

$page_title = 'Budget Details - ' . htmlspecialchars($budget['budget_name']);
require_once '../../includes/header.php';
?>

<style>
.budget-header-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

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
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 1.5rem;
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

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

.status-badge.draft {
    background: rgba(255,255,255,0.2);
}

.status-badge.approved {
    background: rgba(23, 162, 184, 0.3);
}

.status-badge.active {
    background: rgba(40, 167, 69, 0.3);
}

.status-badge.closed {
    background: rgba(220, 53, 69, 0.3);
}

.table-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
}

.variance-positive {
    color: #28a745;
    font-weight: 600;
}

.variance-negative {
    color: #dc3545;
    font-weight: 600;
}

.variance-neutral {
    color: #6c757d;
}

.progress-bar-custom {
    height: 20px;
    border-radius: 10px;
    background: #e9ecef;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    transition: width 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
}

.progress-fill.under {
    background: linear-gradient(90deg, #28a745, #20c997);
}

.progress-fill.at-budget {
    background: linear-gradient(90deg, #17a2b8, #138496);
}

.progress-fill.over {
    background: linear-gradient(90deg, #ffc107, #fd7e14);
}

.progress-fill.exceeded {
    background: linear-gradient(90deg, #dc3545, #c82333);
}

.summary-card {
    background: white;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    border-left: 4px solid #007bff;
}

.summary-card.asset { border-left-color: #28a745; }
.summary-card.liability { border-left-color: #dc3545; }
.summary-card.equity { border-left-color: #6f42c1; }
.summary-card.revenue { border-left-color: #17a2b8; }
.summary-card.expense { border-left-color: #ffc107; }
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-calculator text-primary me-2"></i>Budget Details
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage budget lines and track variance</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="budgets.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Budgets
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

        <!-- Budget Header -->
        <div class="budget-header-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-2">
                        <i class="fas fa-calculator me-2"></i>
                        <?php echo htmlspecialchars($budget['budget_name']); ?>
                    </h2>
                    <div class="mb-2">
                        <span class="status-badge <?php echo $budget['status']; ?>">
                            <?php echo ucfirst($budget['status']); ?>
                        </span>
                    </div>
                    <p class="mb-1 opacity-75">
                        <i class="fas fa-calendar me-2"></i>
                        FY <?php echo $budget['fiscal_year']; ?> - 
                        <?php echo ucfirst($budget['budget_period']); ?> Budget
                    </p>
                    <p class="mb-0 opacity-75">
                        <?php echo date('M d, Y', strtotime($budget['start_date'])); ?> - 
                        <?php echo date('M d, Y', strtotime($budget['end_date'])); ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="opacity-75 mb-1">Total Budget</div>
                    <div style="font-size: 2.5rem; font-weight: 700;">
                        TSH <?php echo number_format($total_budgeted); ?>
                    </div>
                    <div class="opacity-75 small">
                        Created by <?php echo htmlspecialchars($budget['created_by_name']); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number">TSH <?php echo number_format($total_budgeted); ?></div>
                    <div class="stats-label">Total Budgeted</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format($total_actual); ?></div>
                    <div class="stats-label">Actual Spent</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card <?php echo $total_variance >= 0 ? 'success' : 'danger'; ?>">
                    <div class="stats-number">TSH <?php echo number_format(abs($total_variance)); ?></div>
                    <div class="stats-label"><?php echo $total_variance >= 0 ? 'Under' : 'Over'; ?> Budget</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($utilization_percentage, 1); ?>%</div>
                    <div class="stats-label">Utilization</div>
                </div>
            </div>
        </div>

        <!-- Overall Progress -->
        <div class="table-card">
            <h5 class="mb-3">Overall Budget Progress</h5>
            <div class="progress-bar-custom">
                <?php
                $progress_class = 'under';
                if ($utilization_percentage > 110) {
                    $progress_class = 'exceeded';
                } elseif ($utilization_percentage > 100) {
                    $progress_class = 'over';
                } elseif ($utilization_percentage >= 95) {
                    $progress_class = 'at-budget';
                }
                ?>
                <div class="progress-fill <?php echo $progress_class; ?>" 
                     style="width: <?php echo min($utilization_percentage, 100); ?>%">
                    <?php echo number_format($utilization_percentage, 1); ?>%
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-4">
                    <small class="text-muted">Lines Over Budget: </small>
                    <strong class="text-danger"><?php echo $lines_over_budget; ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Lines Under Budget: </small>
                    <strong class="text-success"><?php echo $lines_under_budget; ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Total Lines: </small>
                    <strong><?php echo count($budget_lines); ?></strong>
                </div>
            </div>
        </div>

        <!-- Summary by Account Type -->
        <?php if (!empty($summary_by_type)): ?>
        <div class="table-card">
            <h5 class="section-title">
                <i class="fas fa-chart-pie me-2"></i>Summary by Account Type
            </h5>
            <div class="row">
                <?php foreach ($summary_by_type as $type => $data): ?>
                <div class="col-md-6 mb-3">
                    <div class="summary-card <?php echo $type; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1"><?php echo ucfirst($type); ?></h6>
                                <small class="text-muted"><?php echo $data['count']; ?> line(s)</small>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold">TSH <?php echo number_format($data['budgeted']); ?></div>
                                <small class="text-muted">Budgeted</small>
                            </div>
                        </div>
                        <div class="mt-2">
                            <div class="d-flex justify-content-between">
                                <small>Actual: TSH <?php echo number_format($data['actual']); ?></small>
                                <small class="<?php echo $data['variance'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                    Variance: TSH <?php echo number_format(abs($data['variance'])); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Budget Lines -->
        <div class="table-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="section-title mb-0">
                    <i class="fas fa-list me-2"></i>Budget Lines
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lineModal">
                    <i class="fas fa-plus-circle me-1"></i> Add Budget Line
                </button>
            </div>

            <?php if (empty($budget_lines)): ?>
            <div class="text-center py-5">
                <i class="fas fa-list fa-3x text-muted mb-3"></i>
                <h5>No Budget Lines</h5>
                <p class="text-muted">Add budget lines to track spending by account</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lineModal">
                    <i class="fas fa-plus-circle me-1"></i> Add Budget Line
                </button>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Account Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th class="text-end">Budgeted</th>
                            <th class="text-end">Actual</th>
                            <th class="text-end">Variance</th>
                            <th class="text-end">%</th>
                            <th>Progress</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($budget_lines as $line): ?>
                        <?php
                        $variance = $line['budgeted_amount'] - $line['actual_amount'];
                        $line_percentage = $line['budgeted_amount'] > 0 ? ($line['actual_amount'] / $line['budgeted_amount']) * 100 : 0;
                        $progress_class = 'under';
                        if ($line_percentage > 110) {
                            $progress_class = 'exceeded';
                        } elseif ($line_percentage > 100) {
                            $progress_class = 'over';
                        } elseif ($line_percentage >= 95) {
                            $progress_class = 'at-budget';
                        }
                        ?>
                        <tr>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($line['account_code']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($line['account_name']); ?></td>
                            <td>
                                <span class="badge bg-info"><?php echo ucfirst($line['account_type']); ?></span>
                            </td>
                            <td class="text-end fw-bold">TSH <?php echo number_format($line['budgeted_amount'], 2); ?></td>
                            <td class="text-end">TSH <?php echo number_format($line['actual_amount'], 2); ?></td>
                            <td class="text-end">
                                <span class="<?php echo $variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                    TSH <?php echo number_format(abs($variance), 2); ?>
                                    <?php if ($variance < 0): ?>
                                        <i class="fas fa-arrow-up"></i>
                                    <?php else: ?>
                                        <i class="fas fa-arrow-down"></i>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <span class="<?php echo $line_percentage > 100 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo number_format($line_percentage, 1); ?>%
                                </span>
                            </td>
                            <td style="width: 150px;">
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar <?php echo $line_percentage > 100 ? 'bg-danger' : 'bg-success'; ?>" 
                                         role="progressbar" 
                                         style="width: <?php echo min($line_percentage, 100); ?>%">
                                        <?php echo number_format($line_percentage, 0); ?>%
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" 
                                            class="btn btn-outline-primary"
                                            onclick="editLine(<?php echo htmlspecialchars(json_encode($line)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" 
                                            class="btn btn-outline-danger"
                                            onclick="deleteLine(<?php echo $line['budget_line_id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="3">TOTAL</td>
                            <td class="text-end">TSH <?php echo number_format($total_budgeted, 2); ?></td>
                            <td class="text-end">TSH <?php echo number_format($total_actual, 2); ?></td>
                            <td class="text-end">
                                <span class="<?php echo $total_variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                    TSH <?php echo number_format(abs($total_variance), 2); ?>
                                </span>
                            </td>
                            <td class="text-end"><?php echo number_format($utilization_percentage, 1); ?>%</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</section>

<!-- Add/Edit Budget Line Modal -->
<div class="modal fade" id="lineModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-plus-circle me-2"></i>Add Budget Line
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="lineForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add_line">
                    <input type="hidden" name="budget_line_id" id="budget_line_id">

                    <div class="mb-3">
                        <label class="form-label">Account <span class="text-danger">*</span></label>
                        <select name="account_id" id="account_id" class="form-select" required>
                            <option value="">Select Account</option>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?php echo $account['account_id']; ?>">
                                    <?php echo htmlspecialchars($account['account_code']); ?> - 
                                    <?php echo htmlspecialchars($account['account_name']); ?> 
                                    (<?php echo ucfirst($account['account_type']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Budgeted Amount <span class="text-danger">*</span></label>
                        <input type="number" name="budgeted_amount" id="budgeted_amount" 
                               class="form-control" step="0.01" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Actual Amount</label>
                        <input type="number" name="actual_amount" id="actual_amount" 
                               class="form-control" step="0.01" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Line
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editLine(line) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Budget Line';
    document.getElementById('formAction').value = 'update_line';
    document.getElementById('budget_line_id').value = line.budget_line_id;
    document.getElementById('account_id').value = line.account_id;
    document.getElementById('budgeted_amount').value = line.budgeted_amount;
    document.getElementById('actual_amount').value = line.actual_amount;
    
    const modal = new bootstrap.Modal(document.getElementById('lineModal'));
    modal.show();
}

function deleteLine(lineId) {
    if (confirm('Are you sure you want to delete this budget line?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_line">
            <input type="hidden" name="budget_line_id" value="${lineId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset form when modal is closed
document.getElementById('lineModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('lineForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>Add Budget Line';
    document.getElementById('formAction').value = 'add_line';
    document.getElementById('budget_line_id').value = '';
});
</script>

<?php 
require_once '../../includes/footer.php';
?>